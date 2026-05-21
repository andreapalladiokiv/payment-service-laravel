<?php

declare(strict_types=1);

use Techork\PaymentService\Laravel\Logger\Sanitizer\PhoneNumberSanitizer;

it('matches E.164 phone numbers regardless of field name', function () {
    $sanitizer = new PhoneNumberSanitizer;

    expect($sanitizer->match('whatever', '+14155552671'))->toBeTrue()
        ->and($sanitizer->match('foo', '+442071838750'))->toBeTrue();
});

it('rejects non-parsable values', function () {
    $sanitizer = new PhoneNumberSanitizer;

    expect($sanitizer->match('phone', '5551234567'))->toBeFalse()
        ->and($sanitizer->match('phone', 'not a phone'))->toBeFalse()
        ->and($sanitizer->match('phone', null))->toBeFalse();
});

it('keeps the country code and masks the rest for E.164 numbers', function () {
    $sanitizer = new PhoneNumberSanitizer;

    expect($sanitizer->mask('phone', '+14155552671'))->toBe('+14155******');
});
