<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Laravel\Models\GatewayReference;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

final class EloquentGatewayInstrumentRepository implements GatewayInstrumentRepository, PaymentInstrumentVisitor
{
    public function find(GatewayId $gatewayId, PaymentInstrument $instrument): ?string
    {
        $id = $this->resolveId($instrument);

        if ($id === null) {
            return null;
        }

        return GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', $instrument::type())
            ->where('referenceable_id', $id)
            ->value('reference');
    }

    public function saveReference(GatewayId $gatewayId, PaymentInstrument $instrument, string $reference): void
    {
        $id = $this->resolveId($instrument);

        GatewayReference::query()->upsert(
            [
                'gateway_id' => $gatewayId->toString(),
                'referenceable_type' => $instrument::type(),
                'referenceable_id' => $id,
                'reference' => $reference,
                'failure_reason' => null,
            ],
            ['gateway_id', 'referenceable_type', 'referenceable_id'],
            ['reference', 'failure_reason'],
        );
    }

    /**
     * Kept in the existing `metadata` JSON rather than earning a column: it is
     * one more gateway-specific attribute of the same row, which is what that
     * column is for, and the typed interface keeps the key from becoming a
     * contract callers have to know.
     */
    private const string STORED_CREDENTIAL_KEY = 'stored_credential_reference';

    public function findStoredCredentialReference(GatewayId $gatewayId, PaymentInstrument $instrument): ?string
    {
        $id = $this->resolveId($instrument);

        if ($id === null) {
            return null;
        }

        $metadata = GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', $instrument::type())
            ->where('referenceable_id', $id)
            ->value('metadata');

        $reference = is_array($metadata) ? ($metadata[self::STORED_CREDENTIAL_KEY] ?? null) : null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    public function saveStoredCredentialReference(GatewayId $gatewayId, PaymentInstrument $instrument, string $reference): void
    {
        $id = $this->resolveId($instrument);

        // Read-modify-write rather than upsert: the column carries whatever else a
        // gateway has attached to this instrument, and replacing the whole JSON
        // would drop it.
        $row = GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', $instrument::type())
            ->where('referenceable_id', $id)
            ->first();

        $metadata = is_array($row?->metadata) ? $row->metadata : [];
        $metadata[self::STORED_CREDENTIAL_KEY] = $reference;

        GatewayReference::query()->upsert(
            [
                'gateway_id' => $gatewayId->toString(),
                'referenceable_type' => $instrument::type(),
                'referenceable_id' => $id,
                'metadata' => json_encode($metadata),
            ],
            ['gateway_id', 'referenceable_type', 'referenceable_id'],
            ['metadata'],
        );
    }

    public function saveFailure(GatewayId $gatewayId, PaymentInstrument $instrument, string $reason): void
    {
        $id = $this->resolveId($instrument);

        GatewayReference::query()->upsert(
            [
                'gateway_id' => $gatewayId->toString(),
                'referenceable_type' => $instrument::type(),
                'referenceable_id' => $id,
                'failure_reason' => $reason,
            ],
            ['gateway_id', 'referenceable_type', 'referenceable_id'],
            ['failure_reason'],
        );
    }

    private function resolveId(PaymentInstrument $instrument): ?UuidValueObject
    {
        return $instrument->accept($this);
    }

    public function visitCreditCard(CreditCard $card): null
    {
        return null;
    }

    public function visitCash(Cash $cash): null
    {
        return null;
    }

    public function visitToken(Token $token): TokenId
    {
        return $token->id;
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): PaymentMethodId
    {
        return $paymentMethod->id;
    }

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new \RuntimeException('Hosted-payment instruments are not supported in this context.');
    }
}
