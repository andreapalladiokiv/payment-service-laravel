<?php

declare(strict_types=1);

use Techork\PaymentService\Laravel\Logger\Sanitizer\EmailSanitizer;

it('matches RFC-valid emails regardless of field name', function () {
    $sanitizer = new EmailSanitizer;

    expect($sanitizer->match('whatever', 'john@example.com'))->toBeTrue()
        ->and($sanitizer->match('foo', 'jane.doe+tag@sub.example.co.uk'))->toBeTrue();
});

it('rejects non-email values', function () {
    $sanitizer = new EmailSanitizer;

    expect($sanitizer->match('email', 'not-an-email'))->toBeFalse()
        ->and($sanitizer->match('email', null))->toBeFalse()
        ->and($sanitizer->match('email', '@example.com'))->toBeFalse();
});

it('masks the local-part except the first character', function () {
    $sanitizer = new EmailSanitizer;

    expect($sanitizer->mask('email', 'john@example.com'))->toBe('j***@example.com');
});
