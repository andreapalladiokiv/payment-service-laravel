<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Logger\Sanitizer;

use Illuminate\Support\Str;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;
use Techork\PaymentService\Laravel\Logger\SanitizerInterface;

final readonly class PhoneNumberSanitizer implements SanitizerInterface
{
    public function match(string $name, mixed $value): bool
    {
        try {
            return is_string($value) && PhoneNumberUtil::getInstance()->parse($value);
        } catch (NumberParseException) {
            return false;
        }
    }

    public function mask(string $name, mixed $value): mixed
    {
        $phoneNumber = (string) $value;

        if (str_starts_with($phoneNumber, '+')) {
            return Str::mask($phoneNumber, '*', 6);
        }

        return Str::mask($phoneNumber, '*', 5);
    }
}
