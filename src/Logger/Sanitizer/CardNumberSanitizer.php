<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Logger\Sanitizer;

use Omnipay\Common\CreditCard;
use Omnipay\Common\Helper;
use Techork\PaymentService\Laravel\Logger\SanitizerInterface;

/**
 * Detects PANs by strict shape: a 13–19 character run of pure digits that
 * passes Omnipay's Luhn check. Independent of the context key name, so card
 * numbers carried under arbitrary fields (`cardNumber`, `pan`, `number`, …)
 * are still caught — but only when the value itself is already digit-only,
 * which is how every gateway in this app ships them on the wire. Skipping
 * any character substitution before the Luhn step is deliberate: a permissive
 * strip silently folds non-PAN strings (UUIDs, mixed text) into something
 * Luhn-validates and gets them masked as cards.
 */
final readonly class CardNumberSanitizer implements SanitizerInterface
{
    public function match(string $name, mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $value = (string) $value;
        $length = strlen($value);

        return $length >= 13
            && $length <= 19
            && preg_match('/^\d+$/', $value) === 1
            && Helper::validateLuhn($value);
    }

    public function mask(string $name, mixed $value): string
    {
        return new CreditCard(['number' => (string) $value])->getNumberMasked('*');
    }
}
