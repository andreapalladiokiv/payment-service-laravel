<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use RuntimeException;
use Throwable;

final class Phone implements ValidationRule
{
    public const E164 = 'e164';

    public const INTERNATIONAL = 'international';

    public const NATIONAL = 'national';

    public const RFC3966 = 'rfc3966';

    private ?PhoneNumberFormat $format;

    public function __construct(string $format = '')
    {
        $this->format = match (strtolower($format)) {
            self::E164 => PhoneNumberFormat::E164,
            self::INTERNATIONAL => PhoneNumberFormat::INTERNATIONAL,
            self::NATIONAL => PhoneNumberFormat::NATIONAL,
            self::RFC3966 => PhoneNumberFormat::RFC3966,
            '' => null,
            default => throw new RuntimeException('Invalid format'),
        };
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $phone = PhoneNumberUtil::getInstance()->parse((string) $value);
        } catch (Throwable) {
            $fail('validation.phone.invalid');

            return;
        }

        if ($this->format === null) {
            return;
        }

        PhoneNumberUtil::getInstance()->format($phone, $this->format) === $value
            || $fail('validation.phone.invalid_format');
    }
}
