<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Laravel\Casts\UuidValueObjectCast;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * @property GatewayId $id
 * @property string|null $name
 * @property string $gateway_name
 * @property array<string, string> $credentials
 */
final class Gateway extends Model implements GatewayCredential
{
    use HasUuids;

    protected $fillable = ['name', 'gateway_name', 'credentials'];

    protected $casts = [
        'id' => UuidValueObjectCast::class.':'.GatewayId::class,
        'credentials' => 'encrypted:json',
    ];

    #[Override]
    public function getKey(): string
    {
        return (string) $this->id;
    }

    #[Override]
    public function getId(): GatewayId
    {
        return $this->id;
    }

    #[Override]
    public function getGatewayName(): string
    {
        return $this->gateway_name;
    }

    #[Override]
    public function getCredentials(): array
    {
        return $this->credentials;
    }

    public function references(): HasMany
    {
        return $this->hasMany(GatewayReference::class);
    }
}
