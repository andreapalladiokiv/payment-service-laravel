<?php

namespace Techork\PaymentService\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Money\Currencies;
use Money\Currencies\AggregateCurrencies;
use Money\Currencies\CryptoCurrencies;
use Money\Currencies\ISOCurrencies;

readonly class Currency implements ValidationRule
{
    public const string ISO = 'iso';
    public const string CRYPTO = 'crypto';

    private array $types;

    public function __construct(array $types = [self::ISO, self::CRYPTO])
    {
        $this->types = $types;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->currencies()->contains(new \Money\Currency($value)) || $fail('validation.currency.invalid', $attribute . ' is not a valid currency.');
    }

    private function currencies(): Currencies
    {
        return new AggregateCurrencies(array_map(function (string $type) {
            return match ($type) {
                self::ISO=> new ISOCurrencies(),
                self::CRYPTO => new CryptoCurrencies(),
            };
        }, $this->types));
    }
}