<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service;

use DomainException;
use Money\Money;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregateRepositoryInterface;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Laravel\Webhook\Service\Command\CreateRefund;
use Techork\PaymentService\Laravel\Webhook\Service\Port\ExternallyCompletedRefundPort;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundProcessingRecorder;

/**
 * Default Refund recorder. Refunds live as child aggregates on the parent
 * PaymentIntent's stream — there is no standalone refund repository.
 *
 * The recorder loads the parent PI, then drives `pi.refund(cmd, port)` with
 * a {@see ExternallyCompletedRefundPort} that "replays" the gateway's
 * already-known outcome:
 *   - successful → port returns void → aggregate records `RefundProcessed`
 *   - declined   → port throws → aggregate records `RefundFailed`
 *
 * Idempotency: if {@see TransactionIdResolver} already maps the gateway
 * reference to an internal refund id, we treat the webhook as a duplicate
 * and return Skipped without touching the aggregate.
 */
final readonly class EloquentRefundRecorder implements RefundFailureRecorder, RefundProcessingRecorder
{
    public function __construct(
        private PaymentIntentAggregateRepositoryInterface $paymentIntentRepository,
        private GatewayTransactionRepository $transactionRepository,
        private TransactionIdResolver $resolver,
    ) {}

    public function onRefundProcessed(
        GatewayId $gatewayId,
        string $paymentIntentId,
        string $refundReference,
        Money $amount,
    ): RecorderOutcome {
        return $this->record(
            $gatewayId,
            $paymentIntentId,
            $refundReference,
            $amount,
            ExternallyCompletedRefundPort::successful(),
        );
    }

    public function onRefundFailed(
        GatewayId $gatewayId,
        string $paymentIntentId,
        string $refundReference,
        Money $amount,
        string $reason,
    ): RecorderOutcome {
        return $this->record(
            $gatewayId,
            $paymentIntentId,
            $refundReference,
            $amount,
            ExternallyCompletedRefundPort::declined($reason),
        );
    }

    private function record(
        GatewayId $gatewayId,
        string $paymentIntentId,
        string $refundReference,
        Money $amount,
        ExternallyCompletedRefundPort $port,
    ): RecorderOutcome {
        $piId = PaymentIntentId::fromString($paymentIntentId);
        $paymentIntent = $this->paymentIntentRepository->retrieve($piId);

        if ($paymentIntent->aggregateRootVersion() === 0) {
            return RecorderOutcome::NotFound;
        }

        $resolvedRefundId = $this->resolver->resolveRefund($gatewayId, $refundReference);

        if ($resolvedRefundId !== null) {
            // Resolver maps the gateway reference to an internal refund id —
            // the refund already exists on the PI's stream (Processed or
            // Failed). Either way this webhook is a duplicate.
            return RecorderOutcome::Skipped;
        }

        $refundId = RefundId::generate();

        try {
            $paymentIntent->refund(new CreateRefund($refundId, $amount), $port);
        } catch (DomainException) {
            // PI not in Charged state, currency mismatch, exceeds cap, ... —
            // gateway took the action regardless, but our model rejects it.
            return RecorderOutcome::Skipped;
        }

        $this->paymentIntentRepository->persist($paymentIntent);
        $this->transactionRepository->saveForRefund($gatewayId, $refundId->toString(), $refundReference);

        return RecorderOutcome::Applied;
    }
}
