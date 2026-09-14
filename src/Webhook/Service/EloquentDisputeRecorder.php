<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service;

use DomainException;
use InvalidArgumentException;
use Override;
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeAggregateRepositoryInterface;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeFee;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;
use Techork\PaymentService\Domain\Dispute\ValueObject\FeeType;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregateRepositoryInterface;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeResolution;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\UnmatchedDispute;
use Techork\PaymentService\Laravel\Webhook\Service\Command\ChangeDisputeDeadline;
use Techork\PaymentService\Laravel\Webhook\Service\Command\ChangeDisputeStage;
use Techork\PaymentService\Laravel\Webhook\Service\Command\ChangeDisputeStatus;
use Techork\PaymentService\Laravel\Webhook\Service\Command\OpenDispute;
use Techork\PaymentService\Laravel\Webhook\Service\Command\RecordDisputeFee;

/**
 * Default dispute recorder: the flat DTOs a provider adapter emits, driven onto the aggregate.
 *
 * This is A0, and until it existed no implementation of {@see GatewayDisputeRecorder} was bound
 * anywhere — which did not merely leave disputes unwritten, it took down **every webhook of every
 * provider**: `WebhookServiceProvider::discoverSubscribers()` asks the container for each discovered
 * subscriber, `StripeWebhookSubscriber` requires three dispute handlers, each requires this interface,
 * and the first resolution of `VerifierRegistry` or `HandlerRegistry` threw
 * `BindingResolutionException`. The binding is therefore the repair, not a prerequisite to one.
 *
 * ## What is ours here, and what is the adapter's
 *
 * The snapshot states codes *and* spellings, and the split is deliberate: `$stageCode` /
 * `$statusCode` are the provider's own word — three different vocabularies in one field, since
 * Stripe states a `status` and ConnexPay a `CaseType` — while `$stage` / `$status` are the domain's
 * spellings, which only the adapter that owns the provider's table can fill in. This class resolves
 * the spellings onto the enums and hands the raw codes through to the aggregate as the provider's
 * own code, which is what tells two visits to one stage apart.
 *
 * It therefore holds **no provider table**, and it cannot: `src/Laravel/composer.json` requires no
 * provider package, so `ConnexPay\Dispute\CaseMapping` is invisible from here. That is the whole
 * reason the spelling has to travel beside the code rather than being derived at this end.
 *
 * ## A code it cannot place is refused, never defaulted
 *
 * An unrecognised spelling — or a raw code with **no** spelling beside it, which is a mapping gap in
 * the adapter — throws rather than being read as something. `DisputeStage` has no default to fall
 * back on and says so in its own docblock: mapping an unknown code to `CHARGEBACK` because
 * chargebacks are common would hand the case another phase's deadline and evidence set. The router
 * turns this throw into a retry and, at exhaustion, a visibly `Failed` webhook row rather than a
 * case silently filed wrong. This is the same refusal the brand already gets one layer down.
 *
 * ## Null on an axis means the provider said nothing about it
 *
 * For a case that is only being *moved*, a null stage, status or deadline is not an error: it is the
 * provider stating nothing there, and that axis is left alone. On **opening**, the stage and the
 * status are both required, because the aggregate cannot be created without them and inventing
 * either is the default the paragraph above refuses.
 *
 * ## The two outcomes that are not success
 *
 * `NotFound` is the ordinary answer for a payment we have not observed yet — ConnexPay polls on a
 * schedule and a case can be reported before the sale's own webhook lands — and it is
 * `EloquentRefundRecorder`'s idiom verbatim, down to the `aggregateRootVersion() === 0` test. A
 * `DomainException` from the aggregate (a transition it refuses, a non-positive amount) is
 * `Skipped`: the provider did the thing regardless and retrying cannot change our model's answer.
 *
 * ## What this class does not do
 *
 * It does not route a later cycle to the aggregate an earlier one opened. `DisputeSnapshot::$familyRef`
 * exists for exactly that — ConnexPay re-references a second chargeback inside one family — and there
 * is nowhere to put it: `gateway_references` holds **one** reference per aggregate id and
 * `EloquentGatewayTransactionRepository::save()` is an `updateOrCreate`, so a family key written
 * beside the case key would overwrite it rather than sit next to it. Routing a family therefore needs
 * a storage decision this class cannot take on its own, and until it is taken a later cycle's new
 * reference resolves to nothing and opens a second aggregate for one dispute.
 */
final readonly class EloquentDisputeRecorder implements GatewayDisputeRecorder
{
    public function __construct(
        private PaymentIntentAggregateRepositoryInterface $paymentIntents,
        private DisputeAggregateRepositoryInterface $disputes,
        private GatewayTransactionRepository $references,
        // The concrete resolver, deliberately: `resolveDispute()` is not on the
        // `TransactionIdResolver` interface — a dispute is addressed by the provider's reference for
        // the case, and the interface's two methods are the payment and refund directions.
        private EloquentTransactionIdResolver $resolver,
    ) {}

    #[Override]
    public function onDisputeObserved(GatewayId $gatewayId, string $paymentIntentId, DisputeSnapshot $snapshot): RecorderOutcome
    {
        $paymentIntent = $this->paymentIntents->retrieve(PaymentIntentId::fromString($paymentIntentId));

        if ($paymentIntent->aggregateRootVersion() === 0) {
            // A case cannot be attached to a payment we have never seen. Not an error: the payment's
            // own delivery may simply not have arrived yet, so the caller retries.
            return RecorderOutcome::NotFound;
        }

        $signal = new DisputeSignal($snapshot->providerEventKey, $snapshot->observedAt);
        $known = $this->resolver->resolveDispute($gatewayId, $snapshot->gatewayDisputeRef);

        try {
            if ($known === null) {
                $dispute = DisputeAggregate::open(new OpenDispute(
                    DisputeId::generate(),
                    $paymentIntent->aggregateRootId(),
                    $this->stageOpening($snapshot),
                    $this->statusOpening($snapshot),
                    DisputeReason::fromProviderCode($snapshot->cardBrand, $snapshot->reasonCode),
                    $snapshot->disputedAmount ?? throw new InvalidArgumentException(
                        "A dispute snapshot for case '{$snapshot->gatewayDisputeRef}' states no amount. "
                        .'A case cannot be opened without one, and a zero would book a dispute worth '
                        .'nothing as though the provider had said so.',
                    ),
                    $snapshot->responseDueAt,
                    // The provider's own code for the stage — the cycle position, NOT the stage.
                    // `CaseType` 2 is a second chargeback in the same stage as `CaseType` 1, so a
                    // stage history cannot tell two visits to one stage apart without it.
                    $snapshot->stageCode,
                    $signal,
                ));
            } else {
                $dispute = $this->disputes->retrieve(DisputeId::fromString($known));

                $this->move($dispute, $snapshot, $signal);
            }
        } catch (DomainException) {
            return RecorderOutcome::Skipped;
        }

        $this->disputes->persist($dispute);
        // Written on every observation, not only the first: the row is the only place our id and the
        // provider's name for the case are held side by side, and an upsert of the same pair is a
        // no-op where nothing changed.
        $this->references->saveForDispute($gatewayId, $dispute->aggregateRootId()->toString(), $snapshot->gatewayDisputeRef);

        return RecorderOutcome::Applied;
    }

    #[Override]
    public function onDisputeResolved(GatewayId $gatewayId, string $disputeRef, DisputeResolution $resolution): RecorderOutcome
    {
        $known = $this->resolver->resolveDispute($gatewayId, $disputeRef);

        if ($known === null) {
            // Same answer as an unobserved payment, and for the same reason: the case has not been
            // observed yet, so there is nothing to resolve. A delivery that decides a case before we
            // ever saw it raised is the ordinary arrival order at ConnexPay, not a defect.
            return RecorderOutcome::NotFound;
        }

        $dispute = $this->disputes->retrieve(DisputeId::fromString($known));

        try {
            $dispute->changeStatus(new ChangeDisputeStatus(
                $dispute->aggregateRootId(),
                $this->statusOf(
                    // The spelling, not the provider's word: `FC-CLSD-MF` and `won` are two
                    // vocabularies, and reading either as a `DisputeStatus` happens to work for one
                    // of them and is a coincidence rather than a contract.
                    $resolution->status ?? throw new InvalidArgumentException(
                        "A resolution of case '{$disputeRef}' carries the provider's status code "
                        ."'{$resolution->statusCode}' and no status spelling beside it. A resolution "
                        .'is a decision, so an adapter that cannot say which one it is has not '
                        .'finished reading the delivery.',
                    ),
                    "resolution of case '{$disputeRef}'",
                ),
                new DisputeSignal($resolution->providerEventKey, $resolution->observedAt),
            ));
        } catch (DomainException) {
            return RecorderOutcome::Skipped;
        }

        $this->disputes->persist($dispute);

        return RecorderOutcome::Applied;
    }

    /**
     * ConnexPay's unmatched case, and there is nothing here to hold it.
     *
     * `Skipped` rather than a throw, because the delivery is not wrong — it is a real case against
     * nobody of ours. An aggregate cannot be created without a `PaymentIntentId`, so the alternatives
     * were to invent one or invent a second kind of case, and both would put a payment that does not
     * exist into the domain's trail. The case is not lost either: the caller stores its key whether
     * this answers `Applied` or `Skipped`, so the operator is given it once rather than on every poll,
     * and surfacing it to a human is the read model's job, not this interface's.
     */
    #[Override]
    public function onUnmatchedDispute(GatewayId $gatewayId, UnmatchedDispute $case): RecorderOutcome
    {
        return RecorderOutcome::Skipped;
    }

    /**
     * Everything a delivery states about a case that already exists, in the order the axes read.
     *
     * Every call is unconditional within its axis — the aggregate is what decides whether a statement
     * is news, through its own value guards and its remembered delivery key — so a redelivery moving
     * nothing records nothing without this class having to compare anything itself.
     */
    private function move(DisputeAggregate $dispute, DisputeSnapshot $snapshot, DisputeSignal $signal): void
    {
        if (($stage = $this->stageStated($snapshot)) !== null) {
            $dispute->changeStage(new ChangeDisputeStage(
                $dispute->aggregateRootId(),
                $stage,
                $signal,
                $snapshot->stageCode,
            ));
        }

        if (($status = $this->statusStated($snapshot)) !== null) {
            $dispute->changeStatus(new ChangeDisputeStatus(
                $dispute->aggregateRootId(),
                $status,
                $signal,
            ));
        }

        if ($snapshot->responseDueAt !== null) {
            $dispute->changeDeadline(new ChangeDisputeDeadline(
                $dispute->aggregateRootId(),
                $snapshot->responseDueAt,
                $signal,
                $snapshot->observedAt,
            ));
        }

        foreach ($snapshot->fees as $fee) {
            $dispute->recordFee(new RecordDisputeFee(
                $dispute->aggregateRootId(),
                new DisputeFee(
                    $this->feeType($fee['code'], "case '{$snapshot->gatewayDisputeRef}'"),
                    $fee['amount'],
                    $fee['chargedAt'],
                ),
                $signal,
            ));
        }
    }

    /**
     * The stage a delivery states for a case, or null when it states nothing about the stage.
     *
     * The three-way rule from {@see DisputeSnapshot}'s docblock, and the middle answer is the one
     * that matters: a raw code with no spelling beside it is a **mapping gap in the adapter**, not a
     * stage. Reading `'1'` or `'warning_needs_response'` as a stage would file the case under
     * whichever phase the reader guessed, and the aggregate's own docblock forbids exactly that; so
     * it is refused here, loudly, and the router turns the refusal into a retry and then a visibly
     * failed delivery rather than a case placed wrong.
     */
    private function stageStated(DisputeSnapshot $snapshot): ?DisputeStage
    {
        if ($snapshot->stage === null) {
            return $snapshot->stageCode === null
                ? null
                : throw new InvalidArgumentException(
                    "A dispute snapshot for case '{$snapshot->gatewayDisputeRef}' carries the "
                    ."provider's stage code '{$snapshot->stageCode}' and no stage spelling beside it. "
                    .'The code is not a stage — for ConnexPay it is the cycle position, and for every '
                    .'provider it is that provider\'s own vocabulary — so the adapter that read the '
                    .'payload is the only place it can be mapped. See DisputeSnapshot::$stage.',
                );
        }

        return $this->stageOf($snapshot->stage, "case '{$snapshot->gatewayDisputeRef}'");
    }

    private function statusStated(DisputeSnapshot $snapshot): ?DisputeStatus
    {
        if ($snapshot->status === null) {
            return $snapshot->statusCode === null
                ? null
                : throw new InvalidArgumentException(
                    "A dispute snapshot for case '{$snapshot->gatewayDisputeRef}' carries the "
                    ."provider's status code '{$snapshot->statusCode}' and no status spelling beside "
                    .'it. As with the stage, the code is the provider\'s word and the spelling is the '
                    .'adapter\'s job. See DisputeSnapshot::$status.',
                );
        }

        return $this->statusOf($snapshot->status, "case '{$snapshot->gatewayDisputeRef}'");
    }

    /**
     * The stage a case is opened in — required, because an unopened case has no stage to keep.
     *
     * A delivery that is not about the stage at all cannot open a case: the aggregate takes the
     * stage at creation, and there is nothing to leave alone.
     */
    private function stageOpening(DisputeSnapshot $snapshot): DisputeStage
    {
        return $this->stageStated($snapshot) ?? throw new InvalidArgumentException(
            "A dispute snapshot for case '{$snapshot->gatewayDisputeRef}' states no stage, and the "
            .'case does not exist yet. The stage decides which deadline and which evidence set the '
            .'case carries, so an unopened case cannot be given one by default — the adapter for '
            .'this provider is where the stage is drawn from its payload.',
        );
    }

    private function statusOpening(DisputeSnapshot $snapshot): DisputeStatus
    {
        return $this->statusStated($snapshot) ?? throw new InvalidArgumentException(
            "A dispute snapshot for case '{$snapshot->gatewayDisputeRef}' states no status, and the "
            .'case does not exist yet. A status is required to open one, and the aggregate refuses '
            .'ACCEPTED as a provider signal besides — see the adapter for this provider.',
        );
    }

    private function stageOf(string $code, string $context): DisputeStage
    {
        return DisputeStage::tryFrom($code) ?? throw new InvalidArgumentException(
            "The provider stated stage code '{$code}' for {$context}, and it is not one of "
            .implode(', ', array_column(DisputeStage::cases(), 'value'))
            .'. Nothing maps it here: the adapter that read the payload is the only place that knows '
            ."what the provider means by it, and guessing would put the case on another phase's "
            .'deadline and evidence set.',
        );
    }

    private function statusOf(string $code, string $context): DisputeStatus
    {
        return DisputeStatus::tryFrom($code) ?? throw new InvalidArgumentException(
            "The provider stated status code '{$code}' for {$context}, and it is not one of "
            .implode(', ', array_column(DisputeStatus::cases(), 'value'))
            .'. As with the stage: the adapter that read the payload owns the translation, and a '
            .'default here would decide a case the provider did not.',
        );
    }

    private function feeType(string $code, string $context): FeeType
    {
        return FeeType::tryFrom($code) ?? throw new InvalidArgumentException(
            "The provider stated fee code '{$code}' for {$context}, and it is not one of "
            .implode(', ', array_column(FeeType::cases(), 'value'))
            .'. No adapter produces a fee yet, so this contract is stated rather than exercised — but '
            .'booking a fee under the wrong type moves money in the ledger, which is worse than a '
            .'delivery that fails loudly.',
        );
    }
}
