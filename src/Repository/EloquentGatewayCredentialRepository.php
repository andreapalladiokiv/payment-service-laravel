<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Override;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Laravel\Models\Gateway;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

final class EloquentGatewayCredentialRepository implements GatewayCredentialRepository
{
    #[Override]
    public function findOrFail(GatewayId $gatewayId): GatewayCredential
    {
        return Gateway::query()->findOrFail($gatewayId->toString());
    }

    #[Override]
    public function all(): iterable
    {
        return Gateway::query()->get();
    }
}
