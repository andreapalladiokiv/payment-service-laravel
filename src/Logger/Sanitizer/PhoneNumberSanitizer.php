<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Logger\Sanitizer;

use Illuminate\Support\Str;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Override;
use Techork\PaymentService\Laravel\Logger\SanitizerInterface;

final readonly class PhoneNumberSanitizer implements SanitizerInterface
{
    #[Override]
    public function match(string $name, mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        try {
            // Parsing IS the test: it throws for anything that is not a number, and the
            // result is deliberately unused. The old form `&& parse($value)` read as if the
            // value mattered while a PhoneNumber object is always truthy. Deliberately not
            // isValidNumber(): that is a narrower test and would stop masking things that
            // merely look like numbers, which is the wrong direction in a log.
            PhoneNumberUtil::getInstance()->parse($value);

            return true;
        } catch (NumberParseException) {
            return false;
        }
    }

    #[Override]
    public function mask(string $name, mixed $value): string
    {
        $phoneNumber = (string) $value;

        if (str_starts_with($phoneNumber, '+')) {
            return Str::mask($phoneNumber, '*', 6);
        }

        return Str::mask($phoneNumber, '*', 5);
    }
}
