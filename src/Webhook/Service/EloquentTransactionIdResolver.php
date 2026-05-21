<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service;

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
    public function resolvePaymentIntent(GatewayId $gatewayId, string $reference): ?string
    {
        return GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', EloquentGatewayTransactionRepository::TYPE_PAYMENT_INTENT)
            ->where('reference', $reference)
            ->value('referenceable_id');
    }

    public function resolveRefund(GatewayId $gatewayId, string $reference): ?string
    {
        return GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', EloquentGatewayTransactionRepository::TYPE_REFUND)
            ->where('reference', $reference)
            ->value('referenceable_id');
    }
}
