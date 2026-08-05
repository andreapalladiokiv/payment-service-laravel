<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Override;
use Techork\PaymentService\Gateway\Contract\VirtualCardReferenceRepository;
use Techork\PaymentService\Laravel\Models\GatewayReference;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

final readonly class EloquentVirtualCardReferenceRepository implements VirtualCardReferenceRepository
{
    private const string MORPH_TYPE = 'virtual_card';

    #[Override]
    public function find(GatewayId $gatewayId, string $virtualCardId): ?string
    {
        return GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', self::MORPH_TYPE)
            ->where('referenceable_id', $virtualCardId)
            ->value('reference');
    }

    #[Override]
    public function findVirtualCardId(GatewayId $gatewayId, string $reference): ?string
    {
        return GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', self::MORPH_TYPE)
            ->where('reference', $reference)
            ->value('referenceable_id');
    }

    #[Override]
    public function saveReference(GatewayId $gatewayId, string $virtualCardId, string $reference): void
    {
        GatewayReference::query()
            ->upsert(
                [
                    'gateway_id' => $gatewayId->toString(),
                    'referenceable_type' => self::MORPH_TYPE,
                    'referenceable_id' => $virtualCardId,
                    'reference' => $reference,
                    'failure_reason' => null,
                ],
                ['gateway_id', 'referenceable_type', 'referenceable_id'],
                ['reference', 'failure_reason'],
            );
    }
}
