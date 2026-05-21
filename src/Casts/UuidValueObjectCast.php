<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;

/**
 * Generic cast for any UuidValueObject subclass.
 *
 * Usage in model: 'id' => UuidValueObjectCast::class . ':' . PaymentIntentId::class
 *
 * @implements CastsAttributes<UuidValueObject, string>
 */
final class UuidValueObjectCast implements CastsAttributes
{
    /**
     * @param  class-string<UuidValueObject> $valueObjectClass
     */
    public function __construct(private readonly string $valueObjectClass) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?UuidValueObject
    {
        return $value !== null ? ($this->valueObjectClass)::fromString($value) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof UuidValueObject) {
            return $value->toString();
        }

        return $value;
    }
}
