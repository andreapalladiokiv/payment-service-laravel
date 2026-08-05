<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Rules;

use Closure;
use DateInterval;
use Exception;
use Illuminate\Contracts\Validation\ValidationRule;
use Override;

final class Duration implements ValidationRule
{
    #[Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail("The $attribute must be a valid ISO 8601 duration (e.g. P14D).");

            return;
        }

        try {
            new DateInterval($value);
        } catch (Exception) {
            $fail("The $attribute must be a valid ISO 8601 duration (e.g. P14D).");
        }
    }
}
