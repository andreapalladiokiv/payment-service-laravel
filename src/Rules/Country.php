<?php

namespace Techork\PaymentService\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\Intl\Countries;

class Country implements ValidationRule
{
    public const string ALPHA2 = 'alpha2';

    public const string ALPHA3 = 'alpha3';

    public const string NUMERIC = 'numeric';

    public function __construct(private readonly ?string $format = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->format === null) {
            $valid = Countries::exists($value) || Countries::alpha3CodeExists($value) || Countries::numericCodeExists($value);
        } else {
            $valid = match ($this->format) {
                self::ALPHA2 => Countries::exists($value),
                self::ALPHA3 => Countries::alpha3CodeExists($value),
                self::NUMERIC => Countries::numericCodeExists($value),
            };
        }

        $valid || $fail('validation.country.invalid');
    }
}