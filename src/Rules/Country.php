<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Override;
use Symfony\Component\Intl\Countries;

class Country implements ValidationRule
{
    public const string ALPHA2 = 'alpha2';

    public const string ALPHA3 = 'alpha3';

    public const string NUMERIC = 'numeric';

    private const array FORMATS = [self::ALPHA2, self::ALPHA3, self::NUMERIC];

    /**
     * The format is checked here rather than in `validate()`, the way
     * {@see Phone} does it. A typo used to survive until a request arrived and then
     * surfaced as an `UnhandledMatchError` — a 500 on a user's form submission, from a
     * mistake made when the ruleset was written.
     */
    public function __construct(private readonly ?string $format = null)
    {
        if ($format !== null && ! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown country format "%s"; expected one of %s.',
                $format,
                implode(', ', self::FORMATS),
            ));
        }
    }

    #[Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Symfony's Countries takes a string. A non-string reached it as a TypeError, so a
        // field sent an array or a null answered with a 500 instead of a validation failure.
        // Rejecting it is this rule's job; pair it with `string` when the input is untrusted.
        if (! is_string($value)) {
            $fail('validation.country.invalid');

            return;
        }

        $valid = match ($this->format) {
            self::ALPHA2 => Countries::exists($value),
            self::ALPHA3 => Countries::alpha3CodeExists($value),
            self::NUMERIC => Countries::numericCodeExists($value),
            null => Countries::exists($value) || Countries::alpha3CodeExists($value) || Countries::numericCodeExists($value),
        };

        $valid || $fail('validation.country.invalid');
    }
}