<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service;

use Override;
use Techork\PaymentService\Laravel\Models\GatewayReference;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;

/**
 * Default {@see TransactionIdResolver} implementation. Reverse-looks up
 * gateway-side references in {@see GatewayReference}, scoped by gateway_id for
 * multi-tenant isolation. Returns raw UUID strings; callers in the domain
 * layer wrap them into typed aggregate ids.
 */
final readonly class EloquentTransactionIdResolver implements TransactionIdResolver
{
    #[Override]
    public function resolvePaymentIntent(GatewayId $gatewayId, string $reference): ?string
    {
        return GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', EloquentGatewayTransactionRepository::TYPE_PAYMENT_INTENT)
            ->where('reference', $reference)
            ->value('referenceable_id');
    }

    #[Override]
    public function resolveRefund(GatewayId $gatewayId, string $reference): ?string
    {
        return GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', EloquentGatewayTransactionRepository::TYPE_REFUND)
            ->where('reference', $reference)
            ->value('referenceable_id');
    }

    /**
     * The aggregate id behind a provider's reference for a dispute, or null when no row holds it.
     *
     * The answer a resolution needs. `GatewayDisputeRecorder::onDisputeResolved()` is addressed by
     * the provider's own reference for the case rather than by a PaymentIntent — a resolution can
     * arrive for a case whose payment never resolved, or after a backlog import — so this is the
     * only way a resolution reaches the aggregate it belongs to, and null is the ordinary answer on
     * the first delivery of a case that is about to be observed for the first time.
     *
     * The reference is an opaque string, so it is matched exactly: ConnexPay numbers its cases, and
     * **not all of them are uuids** — that is the value being looked up, and the uuid is what comes
     * back. Scoped by `gateway_id` like the two methods above, for the same reason: a reference is
     * unique within one gateway account and a dispute on one tenant's account must never resolve to
     * another's aggregate.
     *
     * **Deliberately not on {@see TransactionIdResolver}.** That interface is implemented outside
     * this file — the recorder tests alone replace it with an anonymous implementation — so adding a
     * method to it would break every implementation that has no dispute to resolve, and a default
     * method on an interface is not something this tree does. The caller that needs this is the
     * dispute recorder implementation, which holds the concrete collaborator.
     *
     * Not `#[Override]`, for the same reason: there is nothing above this to override.
     */
    public function resolveDispute(GatewayId $gatewayId, string $disputeRef): ?string
    {
        return GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', EloquentGatewayTransactionRepository::TYPE_DISPUTE)
            ->where('reference', $disputeRef)
            ->value('referenceable_id');
    }
}
