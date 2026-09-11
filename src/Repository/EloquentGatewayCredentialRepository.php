<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Illuminate\Database\Eloquent\Model;
use Override;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Laravel\Models\Gateway;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

final readonly class EloquentGatewayCredentialRepository implements GatewayCredentialRepository
{
    /**
     * @param class-string<Model&GatewayCredential> $modelClass
     */
    public function __construct(private string $modelClass = Gateway::class)
    {
    }

    #[Override]
    public function findOrFail(GatewayId $gatewayId): GatewayCredential
    {
        /** @var Model&GatewayCredential $model */
        $model = $this->modelClass::query()->findOrFail($gatewayId->toString());

        return $model;
    }

    #[Override]
    public function all(): iterable
    {
        /** @var iterable<Model&GatewayCredential> $models */
        $models = $this->modelClass::query()->cursor();

        return $models;
    }
}
