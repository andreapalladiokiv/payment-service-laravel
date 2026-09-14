<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Override;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Laravel\Models\GatewayReference;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Backs {@see GatewayTransactionRepository} via the polymorphic
 * {@see GatewayReference} table with morph types `payment_intent`, `refund` and
 * `dispute`.
 *
 * Semantics: one row per (gateway, aggregate) — writes overwrite on transition
 * (e.g. PaymentIntent auth-ref → charge-ref on capture).
 */
final readonly class EloquentGatewayTransactionRepository implements GatewayTransactionRepository
{
    public const string TYPE_PAYMENT_INTENT = 'payment_intent';

    public const string TYPE_REFUND = 'refund';

    /**
     * The morph type a dispute's provider reference is stored under.
     *
     * A plain string column with no enum constraint and no morph map registered, which is why this
     * is the whole of the schema change a dispute needs: nothing migrates. The row is written by
     * A0's {@see \Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder}
     * implementation when a case is observed — the aggregate no longer carries the provider's
     * reference, so the recorder is the one place that holds both halves at once — and it is read
     * in both directions: by our aggregate id, from the four dispute port adapters, and by the
     * provider's reference, from
     * {@see \Techork\PaymentService\Laravel\Webhook\Service\EloquentTransactionIdResolver::resolveDispute()},
     * which is how a resolution addressed by the provider's own name reaches its aggregate.
     *
     * The one thing this type forbids is asking the row for its `referenceable()`. With no morph
     * map entry, `MorphTo` would look for a class named after this string and fail — every reader
     * of a dispute reference queries the table by `referenceable_type` and `reference` instead,
     * which is what the resolver above does.
     */
    public const string TYPE_DISPUTE = 'dispute';

    public function __construct(private string $modelClass = GatewayReference::class)
    {
    }

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

    #[Override]
    public function findForDispute(string $disputeId): ?string
    {
        return $this->find(self::TYPE_DISPUTE, $disputeId);
    }

    #[Override]
    public function saveForDispute(GatewayId $gatewayId, string $disputeId, string $reference): void
    {
        $this->save($gatewayId, self::TYPE_DISPUTE, $disputeId, $reference);
    }

    private function find(string $referenceableType, string $referenceableId): ?string
    {
        return $this->modelClass::query()
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

        $this->modelClass::unguarded(fn () => $this->modelClass::query()->updateOrCreate(
            [
                'gateway_id' => $gatewayId->toString(),
                'referenceable_type' => $referenceableType,
                'referenceable_id' => $referenceableId,
            ],
            [
                'reference' => $reference,
                'failure_reason' => null,
                ...($metadata === [] ? [] : ['metadata' => [...$existing, ...$metadata]]),
            ],
        ));
    }

    /** @return array<string, mixed> */
    private function findMetadata(string $referenceableType, string $referenceableId): array
    {
        $metadata = $this->modelClass::query()
            ->where('referenceable_type', $referenceableType)
            ->where('referenceable_id', $referenceableId)
            ->value('metadata');

        return $metadata ?: [];
    }
}
