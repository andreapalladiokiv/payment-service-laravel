<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Logger\Sanitizer;

use Illuminate\Support\Str;
use Override;
use Techork\PaymentService\Laravel\Logger\SanitizerInterface;

final readonly class EmailSanitizer implements SanitizerInterface
{
    #[Override]
    public function match(string $name, mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    #[Override]
    public function mask(string $name, mixed $value): string
    {
        $email = (string) $value;
        $atPos = Str::position($email, '@');

        if ($atPos === false || $atPos < 1) {
            return Str::mask($email, '*', 0);
        }

        return Str::mask($email, '*', 1, $atPos - 1);
    }
}
