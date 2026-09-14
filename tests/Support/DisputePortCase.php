<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\Command\AcceptDisputeCommand;
use Techork\PaymentService\Domain\Dispute\Command\AttachDisputeEvidenceCommand;
use Techork\PaymentService\Domain\Dispute\Command\ChangeDisputeStatusCommand;
use Techork\PaymentService\Domain\Dispute\Command\OpenDisputeCommand;
use Techork\PaymentService\Domain\Dispute\Command\SubmitDisputeEvidenceCommand;
use Techork\PaymentService\Domain\Dispute\Command\UploadDisputeEvidenceCommand;
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\Port\AcceptOutcome;
use Techork\PaymentService\Domain\Dispute\Port\Request\AcceptDisputeRequest;
use Techork\PaymentService\Domain\Dispute\Port\Request\AvailableActionsRequest;
use Techork\PaymentService\Domain\Dispute\Port\Request\SubmitEvidenceRequest;
use Techork\PaymentService\Domain\Dispute\Port\SubmissionOutcome;
use Techork\PaymentService\Domain\Dispute\SubmissionState;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceFormat;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceItem;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidencePackage;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\DisputeCaseReading;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * One dispute case, and the whole vocabulary of commands and requests that act on it, for the
 * tests of the ports that reach it.
 *
 * ## Why this is a class and not a helper function per file
 *
 * Three test files exercise the same case from three directions — the submission port, the
 * concession port, the action set — and each of them needs a real F1 aggregate and the anonymous
 * implementations of the domain's command interfaces that acting on it requires. Two of those
 * implementations are twenty lines of constructor and three accessors apiece, and a copy of them
 * in every file is a copy that drifts.
 *
 * The repository's other suites put their fixtures in global functions declared in the test file
 * that uses them, which is why every name there carries its file's prefix. That works while each
 * file is the only one interested in its fixtures; here the fixtures are the *subject* shared by
 * three files, so they live in the suite's support namespace instead — where a name is scoped and
 * two files cannot collide on one, which is the failure mode the prefix convention exists to avoid.
 *
 * ## What is real and what is not
 *
 * The aggregate, the commands, the evidence and the requests are all the real types from the
 * domain, built the way the application builds them. Nothing here fakes an aggregate's behaviour:
 * a test that says the case ended up in `DRAFT` is reading {@see DisputeAggregate::submissionState()}
 * of an aggregate whose transition rules were never bypassed. The only thing this file decides is
 * *which* command an outcome becomes, and that decision is spelled out in
 * {@see self::answerSubmission()} and {@see self::answerAcceptance()} rather than hidden in a
 * helper that silently applies something.
 *
 * ## The provider's reference, and why the table is part of the fixture
 *
 * A case is named by us and by the provider, and the two names are no longer carried together: the
 * requests and the aggregate speak only {@see DisputeId}, and `gateway_references` is where the
 * provider's name for the case is held against it. So the shared fixture owns the row as well as the
 * case — {@see self::references()} — because an adapter handed a case id and no table has nothing to
 * address the provider with, and a fixture that made every caller assemble the same row would be the
 * copy that drifts that this file exists to avoid.
 */
final class DisputePortCase
{
    /** The provider's own reference for the case, which is what the adapter reads out of the table. */
    public const string REFERENCE = 'dp_1QkDisputeCaseAlpha';

    /** Ours, never the provider's — the aggregate's identity, and the key the row is written under. */
    public const string DISPUTE_ID = '01961f5a-0000-7000-8000-0000000000bb';

    /** The account the fixture's rows belong to. The lookup is by our id, so this is not part of it. */
    public const string GATEWAY_ID = '01961f5a-0000-7000-8000-0000000000cc';

    /**
     * The reference table with this case's row in it — the state A0's recorder leaves behind when it
     * observes a case, and the only thing an adapter has to go on.
     *
     * Seeded through `saveForDispute()` rather than by handing a map to a constructor, because that
     * is the write the recorder makes: a fixture that seeded itself another way would stay green on
     * a table whose write path had changed shape underneath it.
     */
    public static function references(): DisputeReferenceTable
    {
        $references = new DisputeReferenceTable;
        $references->saveForDispute(GatewayId::fromString(self::GATEWAY_ID), self::DISPUTE_ID, self::REFERENCE);

        return $references;
    }

    public static function at(string $at = '2026-09-14T09:00:00+00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($at);
    }

    public static function id(string $id = self::DISPUTE_ID): DisputeId
    {
        return DisputeId::fromString($id);
    }

    /**
     * A case F1 owns, opened from the delivery that described it.
     *
     * Opened rather than reconstituted from events, because opening is the only way an aggregate
     * comes into existence ({@see DisputeAggregate::open()}) and a case assembled any other way
     * would be a fixture the aggregate could not have produced. `NEEDS_RESPONSE` is the default
     * because it is the state both submissions and concessions are made from, and the deadline is
     * the provider's own — `null` would leave a case that no operator could be given a task with.
     *
     * The stage is a parameter because it is one of the two axes the action rule reads: a case in
     * `INQUIRY` is the one stage the concession is withheld on, and that is a statement about the
     * case rather than about any provider's payload, so it can only be pinned from here.
     */
    public static function open(
        DisputeStatus $status = DisputeStatus::NeedsResponse,
        DisputeStage $stage = DisputeStage::Chargeback,
    ): DisputeAggregate {
        $id = self::id();

        return DisputeAggregate::open(new class($id, $status, $stage) implements OpenDisputeCommand
        {
            public function __construct(
                private readonly DisputeId $id,
                private readonly DisputeStatus $status,
                private readonly DisputeStage $stage,
            ) {}

            public function disputeId(): DisputeId
            {
                return $this->id;
            }

            public function paymentIntentId(): PaymentIntentId
            {
                return PaymentIntentId::fromString('01961f5a-0000-7000-8000-0000000000aa');
            }

            public function stage(): DisputeStage
            {
                return $this->stage;
            }

            public function status(): DisputeStatus
            {
                return $this->status;
            }

            public function reason(): DisputeReason
            {
                return DisputeReason::fromProviderCode(CardBrand::Visa, '10.4');
            }

            public function disputedAmount(): Money
            {
                return new Money(25000, new Currency('USD'));
            }

            public function deadlineAt(): ?DateTimeImmutable
            {
                return DisputePortCase::at('2026-09-28T00:00:00+00:00');
            }

            public function providerCode(): ?string
            {
                return null;
            }

            public function signal(): DisputeSignal
            {
                return new DisputeSignal('evt_1QkDisputeOpened', DisputePortCase::at('2026-09-13T08:00:00+00:00'));
            }
        });
    }

    /**
     * A case whose response we have already filed — `SUBMITTED` on the submission axis and still
     * `NEEDS_RESPONSE` at the provider, which is what the provider keeps saying until it processes
     * the response.
     */
    public static function answered(): DisputeAggregate
    {
        $case = self::open();
        $package = self::package();

        self::answerSubmission(
            $case,
            $package,
            new SubmissionOutcome(SubmissionState::Submitted),
            self::at('2026-09-14T10:00:00+00:00'),
        );

        return $case;
    }

    /**
     * The package the case is answered with: one piece of prose and one document, which is the pair
     * of shapes every mapping on the way out has to tell apart.
     */
    public static function package(): EvidencePackage
    {
        return new EvidencePackage(CardBrand::Visa, '10.4', [
            new EvidenceItem(EvidenceType::StatementDescriptor, 'ACME*SUBSCRIPTION'),
            new EvidenceItem(
                EvidenceType::ProofOfDeliveryOrService,
                base64_encode('%PDF-1.4 the signed delivery note'),
                EvidenceFormat::Pdf,
            ),
        ]);
    }

    /** @return list<EvidenceType> */
    public static function types(?EvidencePackage $package = null): array
    {
        return array_map(
            static fn (EvidenceItem $item): EvidenceType => $item->type,
            ($package ?? self::package())->items(),
        );
    }

    public static function submission(?EvidencePackage $package = null, bool $stageOnly = true): SubmitEvidenceRequest
    {
        return new SubmitEvidenceRequest(
            disputeId: self::id(),
            evidence: $package ?? self::package(),
            stageOnly: $stageOnly,
        );
    }

    public static function acceptance(?Money $partialAmount = null): AcceptDisputeRequest
    {
        return new AcceptDisputeRequest(
            disputeId: self::id(),
            partialAmount: $partialAmount,
        );
    }

    /**
     * The question, in whichever of the two shapes the caller means.
     *
     * With an id it is a case of ours, and the adapter reads the provider's reference out of the
     * table; without one it is the unmatched case, which has no aggregate, no id and no row, and is
     * therefore named by the provider's own reference — {@see self::REFERENCE} here, because the
     * point of the unmatched case is that the name is all there is. The two constructors are how the
     * domain keeps a half-named case from being expressible, so the fixture branches the same way
     * rather than passing nulls through.
     */
    public static function actions(?DisputeId $disputeId = null): AvailableActionsRequest
    {
        return $disputeId === null
            ? AvailableActionsRequest::unattributable(self::REFERENCE)
            : AvailableActionsRequest::ofOurs($disputeId);
    }

    /**
     * A reading, as a provider answers one.
     *
     * `awaitingResponse` drives the deadline because {@see DisputeCaseReading} refuses to be built
     * without one: a case the provider is waiting on and has no deadline for has no response task
     * to describe, and building one here by accident would hide that from the test.
     */
    public static function reading(
        bool $awaiting = true,
        bool $concedable = true,
        ?string $brand = 'visa',
        ?string $reasonCode = '10.4',
        ?DateTimeImmutable $respondBy = null,
    ): DisputeCaseReading {
        return new DisputeCaseReading(
            awaitingResponse: $awaiting,
            concedable: $concedable,
            cardBrand: $brand,
            reasonCode: $reasonCode,
            respondBy: $awaiting ? ($respondBy ?? self::at('2026-09-28T00:00:00+00:00')) : $respondBy,
        );
    }

    /**
     * The application's half of the submission seam: what the case records for the state the port
     * answered with.
     *
     * The match is over every state the port can return rather than over the two this provider
     * produces, because the port is provider-neutral and Nuvei's upload step
     * ({@see SubmissionState::UploadPending}) reaches an aggregate that has to have somewhere to put
     * it. `NONE` and `CONFIRMED` are refused by {@see SubmissionOutcome} itself — a call that just
     * returned submitted nothing and was not acknowledged — and are named here so that a state added
     * to the enum later fails loudly instead of being silently ignored.
     */
    public static function answerSubmission(
        DisputeAggregate $case,
        EvidencePackage $package,
        SubmissionOutcome $outcome,
        DateTimeImmutable $at,
    ): void {
        $types = self::types($package);

        match ($outcome->state) {
            SubmissionState::Draft => $case->attachEvidence(self::attach($case->aggregateRootId(), $types, $at)),
            SubmissionState::UploadPending => $case->uploadEvidence(self::upload($case->aggregateRootId(), $types, $at)),
            SubmissionState::Submitted => $case->submitEvidence(self::submit($case->aggregateRootId(), $types, $at)),
            SubmissionState::None, SubmissionState::Confirmed => throw new LogicException(
                "A submission call returned {$outcome->state->value}, which no call that just returned "
                .'can produce: the outcome type refuses it.',
            ),
        };
    }

    /**
     * The application's half of the concession seam, and the one decision in it: a case the provider
     * had already closed is recorded as nothing at all.
     *
     * That is the whole reason {@see AcceptOutcome} is not a `void` — the provider resolved the case
     * before our call, so recording our concession would book a decision we did not take, at a
     * moment we did not take it.
     */
    public static function answerAcceptance(DisputeAggregate $case, AcceptOutcome $outcome, DateTimeImmutable $at): void
    {
        if ($outcome->wasAlreadyClosed()) {
            return;
        }

        $case->accept(self::accept($case->aggregateRootId(), $at));
    }

    /** @param list<EvidenceType> $types */
    public static function attach(DisputeId $id, array $types, DateTimeImmutable $at): AttachDisputeEvidenceCommand
    {
        return new class($id, $types, $at) implements AttachDisputeEvidenceCommand
        {
            /** @param list<EvidenceType> $types */
            public function __construct(
                private readonly DisputeId $id,
                private readonly array $types,
                private readonly DateTimeImmutable $at,
            ) {}

            public function disputeId(): DisputeId
            {
                return $this->id;
            }

            /** @return list<EvidenceType> */
            public function types(): array
            {
                return $this->types;
            }

            public function at(): DateTimeImmutable
            {
                return $this->at;
            }
        };
    }

    /** @param list<EvidenceType> $types */
    public static function upload(DisputeId $id, array $types, DateTimeImmutable $at): UploadDisputeEvidenceCommand
    {
        return new class($id, $types, $at) implements UploadDisputeEvidenceCommand
        {
            /** @param list<EvidenceType> $types */
            public function __construct(
                private readonly DisputeId $id,
                private readonly array $types,
                private readonly DateTimeImmutable $at,
            ) {}

            public function disputeId(): DisputeId
            {
                return $this->id;
            }

            /** @return list<EvidenceType> */
            public function types(): array
            {
                return $this->types;
            }

            public function at(): DateTimeImmutable
            {
                return $this->at;
            }
        };
    }

    /** @param list<EvidenceType> $types */
    public static function submit(DisputeId $id, array $types, DateTimeImmutable $at): SubmitDisputeEvidenceCommand
    {
        return new class($id, $types, $at) implements SubmitDisputeEvidenceCommand
        {
            /** @param list<EvidenceType> $types */
            public function __construct(
                private readonly DisputeId $id,
                private readonly array $types,
                private readonly DateTimeImmutable $at,
            ) {}

            public function disputeId(): DisputeId
            {
                return $this->id;
            }

            /** @return list<EvidenceType> */
            public function types(): array
            {
                return $this->types;
            }

            public function at(): DateTimeImmutable
            {
                return $this->at;
            }
        };
    }

    public static function accept(DisputeId $id, DateTimeImmutable $at): AcceptDisputeCommand
    {
        return new class($id, $at) implements AcceptDisputeCommand
        {
            public function __construct(private readonly DisputeId $id, private readonly DateTimeImmutable $at) {}

            public function disputeId(): DisputeId
            {
                return $this->id;
            }

            public function at(): DateTimeImmutable
            {
                return $this->at;
            }
        };
    }

    /**
     * A status move as a provider's delivery reports it — the shape the irreversibility test needs,
     * because a delivery is exactly how a case we conceded would be told to move again.
     */
    public static function changeStatus(
        DisputeId $id,
        DisputeStatus $status,
        string $providerEventKey,
        ?DateTimeImmutable $observedAt = null,
    ): ChangeDisputeStatusCommand {
        return new class($id, $status, $providerEventKey, $observedAt ?? self::at('2026-09-15T09:00:00+00:00')) implements ChangeDisputeStatusCommand
        {
            public function __construct(
                private readonly DisputeId $id,
                private readonly DisputeStatus $status,
                private readonly string $key,
                private readonly DateTimeImmutable $observedAt,
            ) {}

            public function disputeId(): DisputeId
            {
                return $this->id;
            }

            public function status(): DisputeStatus
            {
                return $this->status;
            }

            public function signal(): DisputeSignal
            {
                return new DisputeSignal($this->key, $this->observedAt);
            }
        };
    }
}
