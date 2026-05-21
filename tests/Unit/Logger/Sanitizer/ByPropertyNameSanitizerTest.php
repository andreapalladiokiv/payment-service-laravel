<?php

declare(strict_types=1);

use Techork\PaymentService\Laravel\Logger\Sanitizer\ByPropertyNameSanitizer;

it('matches only the configured names with a string value', function () {
    $sanitizer = new ByPropertyNameSanitizer('first_name', 'last_name');

    expect($sanitizer->match('first_name', 'John'))->toBeTrue()
        ->and($sanitizer->match('last_name', 'Doe'))->toBeTrue()
        ->and($sanitizer->match('email', 'john@example.com'))->toBeFalse()
        ->and($sanitizer->match('first_name', null))->toBeFalse()
        ->and($sanitizer->match('first_name', 42))->toBeFalse();
});

it('masks the entire value with asterisks', function () {
    $sanitizer = new ByPropertyNameSanitizer('first_name');

    expect($sanitizer->mask('first_name', 'John'))->toBe('****');
});
