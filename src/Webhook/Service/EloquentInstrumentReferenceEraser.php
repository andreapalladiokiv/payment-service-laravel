<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service;

use Techork\PaymentService\Gateway\Webhook\Contract\InstrumentReferenceEraser;
use Techork\PaymentService\Common\ValueObject\PaymentMethod as PaymentMethodValueObject;
use Techork\PaymentService\Laravel\Models\GatewayReference;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Default {@see InstrumentReferenceEraser} implementation. Deletes the
 * (gateway_id, referenceable_type=PaymentMethod, reference) row in
 * {@see GatewayReference} so the gateway linkage is forgotten while the local
 * PaymentMethod record survives.
 */
final readonly class EloquentInstrumentReferenceEraser implements InstrumentReferenceEraser
{
    public function forgetPaymentMethodReference(GatewayId $gatewayId, string $reference): bool
    {
        $deleted = GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', PaymentMethodValueObject::type())
            ->where('reference', $reference)
            ->delete();

        return $deleted > 0;
    }
}
