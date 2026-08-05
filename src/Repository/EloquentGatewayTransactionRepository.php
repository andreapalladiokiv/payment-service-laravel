<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Override;
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

    #[Override]
    public function findForPaymentIntent(string $paymentIntentId): ?string
    {
        return $this->find(self::TYPE_PAYMENT_INTENT, $paymentIntentId);
    }

    #[Override]
    public function saveForPaymentIntent(GatewayId $gatewayId, string $paymentIntentId, string $reference, array $metadata = []): void
    {
        $this->save($gatewayId, self::TYPE_PAYMENT_INTENT, $paymentIntentId, $reference, $metadata);
    }

    #[Override]
    public function findMetadataForPaymentIntent(string $paymentIntentId): array
    {
        return $this->findMetadata(self::TYPE_PAYMENT_INTENT, $paymentIntentId);
    }

    #[Override]
    public function findForRefund(string $refundId): ?string
    {
        return $this->find(self::TYPE_REFUND, $refundId);
    }

    #[Override]
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function save(GatewayId $gatewayId, string $referenceableType, string $referenceableId, string $reference, array $metadata = []): void
    {
        // MERGED, not replaced. An empty array means "no signal", not "erase" —
        // overwrite-on-transition applies to the reference only — and replacing the
        // whole bag honoured that just as long as the later response happened to
        // carry nothing. The moment a capture returns metadata of its own (ConnexPay
        // returns its incoming transaction code there) everything the authorization
        // recorded was dropped, which is the opposite of what the rule above says.
        // Same-key writes still win, so a value the later response does repeat is
        // updated rather than pinned.
        $existing = $metadata === [] ? [] : $this->findMetadata($referenceableType, $referenceableId);

        GatewayReference::unguarded(fn () => GatewayReference::query()->updateOrCreate(
            [
                'gateway_id' => $gatewayId->toString(),
                'referenceable_type' => $referenceableType,
                'referenceable_id' => $referenceableId,
            ],
            [
                'reference' => $reference,
                'failure_reason' => null,
                ...($metadata === [] ? [] : ['metadata' => json_encode([...$existing, ...$metadata])]),
            ],
        ));
    }

    /** @return array<string, mixed> */
    private function findMetadata(string $referenceableType, string $referenceableId): array
    {
        $metadata = GatewayReference::query()
            ->where('referenceable_type', $referenceableType)
            ->where('referenceable_id', $referenceableId)
            ->value('metadata');

        if ($metadata === null || $metadata === '') {
            return [];
        }

        $decoded = json_decode((string) $metadata, true);

        return is_array($decoded) ? $decoded : [];
    }
}
