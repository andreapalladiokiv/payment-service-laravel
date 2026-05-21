<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Laravel\Models\GatewayReference;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Backs {@see GatewayTransactionRepository} via the polymorphic
 * {@see GatewayReference} table with morph types `payment_intent` and `refund`.
 *
 * Semantics: one row per (gateway, aggregate) — writes overwrite on transition
 * (e.g. PaymentIntent auth-ref → charge-ref on capture).
 */
final class EloquentGatewayTransactionRepository implements GatewayTransactionRepository
{
    public const string TYPE_PAYMENT_INTENT = 'payment_intent';

    public const string TYPE_REFUND = 'refund';

    public function findForPaymentIntent(string $paymentIntentId): ?string
    {
        return $this->find(self::TYPE_PAYMENT_INTENT, $paymentIntentId);
    }

    public function saveForPaymentIntent(GatewayId $gatewayId, string $paymentIntentId, string $reference): void
    {
        $this->save($gatewayId, self::TYPE_PAYMENT_INTENT, $paymentIntentId, $reference);
    }

    public function findForRefund(string $refundId): ?string
    {
        return $this->find(self::TYPE_REFUND, $refundId);
    }

    public function saveForRefund(GatewayId $gatewayId, string $refundId, string $reference): void
    {
        $this->save($gatewayId, self::TYPE_REFUND, $refundId, $reference);
    }

    private function find(string $referenceableType, string $referenceableId): ?string
    {
        return GatewayReference::query()
            ->where('referenceable_type', $referenceableType)
            ->where('referenceable_id', $referenceableId)
            ->value('reference');
    }

    private function save(GatewayId $gatewayId, string $referenceableType, string $referenceableId, string $reference): void
    {
        GatewayReference::unguarded(fn () => GatewayReference::query()->updateOrCreate(
            [
                'gateway_id' => $gatewayId->toString(),
                'referenceable_type' => $referenceableType,
                'referenceable_id' => $referenceableId,
            ],
            [
                'reference' => $reference,
                'failure_reason' => null,
            ],
        ));
    }
}
