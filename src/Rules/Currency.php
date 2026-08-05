<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Money\Currencies;
use Money\Currencies\AggregateCurrencies;
use Money\Currencies\CryptoCurrencies;
use Money\Currencies\ISOCurrencies;
use Override;

readonly class Currency implements ValidationRule
{
    public const string ISO = 'iso';
    public const string CRYPTO = 'crypto';

    private const array TYPES = [self::ISO, self::CRYPTO];

    /** @var list<string> */
    private array $types;

    /**
     * @param list<string> $types
     *
     * Checked here rather than in `validate()`: an unknown type used to reach the `match`
     * inside `currencies()` and surface as an `UnhandledMatchError` on a user's request,
     * long after the mistake was made.
     */
    public function __construct(array $types = self::TYPES)
    {
        foreach ($types as $type) {
            if (! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown currency type "%s"; expected one of %s.',
                    is_string($type) ? $type : get_debug_type($type),
                    implode(', ', self::TYPES),
                ));
            }
        }

        $this->types = $types;
    }

    #[Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Money's Currency takes a non-empty string, so a non-string arrived as a TypeError
        // and an empty one as an argument error — a 500 where the caller asked for validation.
        if (! is_string($value) || $value === '') {
            $fail('validation.currency.invalid', $attribute.' is not a valid currency.');

            return;
        }

        $this->currencies()->contains(new \Money\Currency($value))
            || $fail('validation.currency.invalid', $attribute.' is not a valid currency.');
    }

    private function currencies(): Currencies
    {
        return new AggregateCurrencies(array_map(static fn (string $type): Currencies => match ($type) {
            self::ISO => new ISOCurrencies,
            self::CRYPTO => new CryptoCurrencies,
        }, $this->types));
    }
}