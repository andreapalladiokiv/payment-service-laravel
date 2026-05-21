<?php

declare(strict_types=1);

use Techork\PaymentService\Laravel\Logger\Sanitizer\CardNumberSanitizer;

it('matches Luhn-valid 13–19-digit strings regardless of field name', function () {
    $sanitizer = new CardNumberSanitizer;

    expect($sanitizer->match('whatever', '4242424242424242'))->toBeTrue()
        ->and($sanitizer->match('foo', '4111111111111111'))->toBeTrue();
});

it('rejects PANs carrying group separators (gateways ship raw digits)', function () {
    $sanitizer = new CardNumberSanitizer;

    expect($sanitizer->match('bar', '4242 4242 4242 4242'))->toBeFalse()
        ->and($sanitizer->match('bar', '4242-4242-4242-4242'))->toBeFalse();
});

it('rejects numbers outside the 13–19 length window', function () {
    $sanitizer = new CardNumberSanitizer;

    expect($sanitizer->match('any', '424242424242'))->toBeFalse()
        ->and($sanitizer->match('any', '42424242424242424242'))->toBeFalse();
});

it('rejects strings that fail the Luhn checksum', function () {
    $sanitizer = new CardNumberSanitizer;

    expect($sanitizer->match('any', '4242424242424241'))->toBeFalse()
        ->and($sanitizer->match('any', '1234567890123456'))->toBeFalse();
});

it('rejects non-scalar and non-numeric inputs', function () {
    $sanitizer = new CardNumberSanitizer;

    expect($sanitizer->match('any', null))->toBeFalse()
        ->and($sanitizer->match('any', 'not-a-pan'))->toBeFalse()
        ->and($sanitizer->match('any', ['4242424242424242']))->toBeFalse();
});

it('rejects UUIDs whose digit residue accidentally satisfies Luhn', function () {
    $sanitizer = new CardNumberSanitizer;

    // Digits of this UUID form `9152444384959471618` — 19 chars, Luhn-valid.
    expect($sanitizer->match('any', '9ccd1a52-4cda-44d3-8495-9cdc4e716c18'))->toBeFalse();
});

it('keeps the last 4 digits visible', function () {
    $sanitizer = new CardNumberSanitizer;

    expect($sanitizer->mask('any', '4242424242424242'))->toBe('************4242');
});
