<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Override;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;

/**
 * Generic cast for any UuidValueObject subclass.
 *
 * Usage in model: 'id' => UuidValueObjectCast::class . ':' . PaymentIntentId::class
 *
 * @implements CastsAttributes<UuidValueObject, string>
 */
final readonly class UuidValueObjectCast implements CastsAttributes
{
    /**
     * @param  class-string<UuidValueObject> $valueObjectClass
     */
    public function __construct(private string $valueObjectClass) {}

    #[Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): ?UuidValueObject
    {
        return $value !== null ? ($this->valueObjectClass)::fromString($value) : null;
    }

    #[Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // Nullable to match get(), which hands back null for an absent column. The old
        // `string` return was a promise the null branch broke.
        if ($value === null) {
            return null;
        }

        if ($value instanceof UuidValueObject) {
            return $value->toString();
        }

        return (string) $value;
    }
}
