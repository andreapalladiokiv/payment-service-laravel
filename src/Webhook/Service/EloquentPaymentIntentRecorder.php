<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service;

use DomainException;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectResult;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregateRepositoryInterface;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Laravel\Webhook\Service\Command\CancelPaymentIntent;
use Techork\PaymentService\Laravel\Webhook\Service\Command\CapturePaymentIntent;
use Techork\PaymentService\Laravel\Webhook\Service\Port\ExternallyCompletedCancelPort;
use Techork\PaymentService\Laravel\Webhook\Service\Port\ExternallyCompletedCapturePort;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayAuthorizationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentIntentRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * Default PaymentIntent recorder. Translates gateway-driven webhook signals
 * into domain transitions on the {@see PaymentIntentAggregate}.
 *
 * In the synchronous-port world the create call already reflects the gateway
 * outcome on the aggregate, so most webhook signals are either:
 *   - duplicates of the inline outcome (Skipped), or
 *   - terminal resolutions of a previously requested challenge (`confirmChallenge`), or
 *   - state changes the gateway made on its own — capture / cancel — which we
 *     replay onto the aggregate via a no-op port adapter so the event
 *     stream stays the source of truth.
 */
final readonly class EloquentPaymentIntentRecorder implements GatewayAuthorizationRecorder, GatewayCancellationRecorder, GatewayFailureRecorder, GatewayPaymentIntentRecorder, GatewaySuccessRecorder
{
    public function __construct(
        private PaymentIntentAggregateRepositoryInterface $paymentIntentRepository,
        private GatewayTransactionRepository $transactionRepository,
        private TransactionIdResolver $transactionIdResolver,
    ) {}

    /**
     * Opens the aggregate for an intent the gateway has just told us about.
     *
     * Imported as {@see PaymentIntentStatus::RequiresAction}, which is not a
     * stand-in for a missing "created" case — it is the state every other
     * recorder here already knows how to resolve. `onGatewaySuccess` and
     * `onGatewayFailure` both branch on `RequiresAction` and finish it through
     * `confirmChallenge()`, and `cancel()` lists it as cancelable. So an intent
     * opened here converges the moment its next webhook lands, which is the
     * whole point: the other recorders return NotFound and retry for exactly as
     * long as nothing has created the intent.
     *
     * The instrument is {@see HostedPayment::unknown()}: Stripe attaches the
     * payment method at confirmation, so the event that announces an intent
     * names none, and `PaymentInstrument` is not nullable.
     */
    public function onPaymentIntentRecord(
        GatewayId $gatewayId,
        string $paymentIntentReference,
        Money $amount,
        ?string $paymentMethodReference,
        ?string $description,
        ?string $merchantDescriptor,
    ): RecorderOutcome {
        // Idempotency, and the case that matters most: our own create call also
        // makes the gateway announce the intent, so this arrives for intents we
        // already hold. Resolving the reference is what tells the two apart.
        if ($this->transactionIdResolver->resolvePaymentIntent($gatewayId, $paymentIntentReference) !== null) {
            return RecorderOutcome::Skipped;
        }

        $id = PaymentIntentId::generate();
        $paymentIntent = $this->paymentIntentRepository->retrieve($id);

        $paymentIntent->import(
            amount: $amount,
            status: PaymentIntentStatus::RequiresAction,
            instrument: HostedPayment::unknown(),
            // Nothing has been captured and nothing says it will be
            // automatically. Manual keeps the aggregate from reporting an
            // authorization it never saw as destined for capture.
            captureMethod: CaptureMethod::Manual,
            // Not null, even though the import event would accept it: the charge,
            // authorize and requires-action events all demand a billing address,
            // so an intent imported with null could never be resolved by the very
            // webhooks this exists to unblock.
            billingAddress: BillingAddress::unknown(),
        );

        $this->paymentIntentRepository->persist($paymentIntent);

        // Last, so a failure here leaves an intent with no reference — which the
        // next webhook retries into existence — rather than a reference pointing
        // at an aggregate that was never persisted.
        $this->transactionRepository->saveForPaymentIntent($gatewayId, $id->toString(), $paymentIntentReference);

        return RecorderOutcome::Applied;
    }

    public function onGatewaySuccess(
        GatewayId $gatewayId,
        string $paymentIntentId,
        string $gatewayReference,
        Money $amount,
    ): RecorderOutcome {
        $id = PaymentIntentId::fromString($paymentIntentId);
        $paymentIntent = $this->paymentIntentRepository->retrieve($id);

        if ($paymentIntent->aggregateRootVersion() === 0) {
            return RecorderOutcome::NotFound;
        }

        switch ($paymentIntent->status()) {
            case PaymentIntentStatus::RequiresAction:
                $paymentIntent->confirmChallenge(new RedirectResult($gatewayReference));
                break;

            case PaymentIntentStatus::Authorized:
                try {
                    $paymentIntent->capture(new CapturePaymentIntent($id, $amount), new ExternallyCompletedCapturePort);
                } catch (DomainException) {
                    // Immediate captureMethod / unsupported transition — gateway
                    // already captured, but the aggregate refuses to record it.
                    return RecorderOutcome::Skipped;
                }
                break;

            default:
                return RecorderOutcome::Skipped;
        }

        $this->paymentIntentRepository->persist($paymentIntent);

        if ($gatewayReference !== '') {
            $this->transactionRepository->saveForPaymentIntent($gatewayId, $paymentIntentId, $gatewayReference);
        }

        return RecorderOutcome::Applied;
    }

    public function onGatewayAuthorization(
        GatewayId $gatewayId,
        string $paymentIntentId,
        string $gatewayReference,
    ): RecorderOutcome {
        $id = PaymentIntentId::fromString($paymentIntentId);
        $paymentIntent = $this->paymentIntentRepository->retrieve($id);

        if ($paymentIntent->aggregateRootVersion() === 0) {
            return RecorderOutcome::NotFound;
        }

        if ($paymentIntent->status() !== PaymentIntentStatus::RequiresAction) {
            // Status was set inline by `pi.create()` — webhook is dup.
            return RecorderOutcome::Skipped;
        }

        $paymentIntent->confirmChallenge(new RedirectResult($gatewayReference));
        $this->paymentIntentRepository->persist($paymentIntent);

        if ($gatewayReference !== '') {
            $this->transactionRepository->saveForPaymentIntent($gatewayId, $paymentIntentId, $gatewayReference);
        }

        return RecorderOutcome::Applied;
    }

    public function onGatewayFailure(string $paymentIntentId, string $reason): RecorderOutcome
    {
        $id = PaymentIntentId::fromString($paymentIntentId);
        $paymentIntent = $this->paymentIntentRepository->retrieve($id);

        if ($paymentIntent->aggregateRootVersion() === 0) {
            return RecorderOutcome::NotFound;
        }

        if ($paymentIntent->status() !== PaymentIntentStatus::RequiresAction) {
            // PaymentIntentFailed already recorded inline by `pi.create()`.
            return RecorderOutcome::Skipped;
        }

        $paymentIntent->confirmChallenge(self::failedThreeDSResult($reason));
        $this->paymentIntentRepository->persist($paymentIntent);

        return RecorderOutcome::Applied;
    }

    public function onGatewayCancellation(string $paymentIntentId): RecorderOutcome
    {
        $id = PaymentIntentId::fromString($paymentIntentId);
        $paymentIntent = $this->paymentIntentRepository->retrieve($id);

        if ($paymentIntent->aggregateRootVersion() === 0) {
            return RecorderOutcome::NotFound;
        }

        try {
            $paymentIntent->cancel(
                new CancelPaymentIntent($id, 'Cancelled at gateway'),
                new ExternallyCompletedCancelPort,
            );
        } catch (DomainException) {
            return RecorderOutcome::Skipped;
        }

        $this->paymentIntentRepository->persist($paymentIntent);

        return RecorderOutcome::Applied;
    }

    /**
     * Synthesises a 3DS-shaped {@see ThreeDSResult} that the
     * {@see \Techork\PaymentService\Domain\PaymentIntent\ChallengeFailureReasonExtractor}
     * recognises as a failure. Used when the webhook tells us the gateway
     * declined a previously requested challenge — we don't actually have the
     * 3DS attestation fields, so they're left blank.
     */
    private static function failedThreeDSResult(string $reason): ThreeDSResult
    {
        return new ThreeDSResult(
            status: ThreeDSStatus::Rejected,
            authenticationValue: null,
            eci: null,
            dsTransactionId: $reason,
            acsTransactionId: '',
            version: null,
        );
    }
}
