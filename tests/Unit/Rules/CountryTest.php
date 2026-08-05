<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Laravel\Rules\Country;

/**
 * Collects what the rule handed to `$fail`; an empty list means accepted.
 *
 * @return list<string>
 */
function countryRuleFailures(Country $rule, mixed $value, string $attribute = 'country'): array
{
    $failures = [];
    $rule->validate($attribute, $value, function (string $message) use (&$failures) {
        $failures[] = $message;
    });

    return $failures;
}

// ─────────────────────────────────────────────────────────
//  Formatless mode: any of the three ISO 3166-1 notations
// ─────────────────────────────────────────────────────────

it('accepts all three ISO notations when no format is pinned', function () {
    expect(countryRuleFailures(new Country, 'US'))->toBe([])
        ->and(countryRuleFailures(new Country, 'USA'))->toBe([])
        ->and(countryRuleFailures(new Country, '840'))->toBe([]);
});

it('rejects a code that exists in no notation', function () {
    expect(countryRuleFailures(new Country, 'XX'))->toBe(['validation.country.invalid'])
        ->and(countryRuleFailures(new Country, ''))->toBe(['validation.country.invalid']);
});

it('rejects a lowercase code even in formatless mode', function () {
    // ICU data is keyed by uppercase codes and the rule does not normalise, so the
    // caller has to upcase before validating. Pinned because the failure looks like
    // "valid country rejected" from the outside.
    expect(countryRuleFailures(new Country, 'us'))->toBe(['validation.country.invalid']);
});

it('rejects the shredding sentinel country', function () {
    // ZZ is what a GDPR-erased address carries. The Country *value object*
    // whitelists it; this rule deliberately does not, so an erased address can never
    // be re-submitted through a validated request.
    expect(countryRuleFailures(new Country, ShreddingStubs::COUNTRY))
        ->toBe(['validation.country.invalid']);
});

// ─────────────────────────────────────────────────────────
//  Pinned notation: the boundary the format argument exists for
// ─────────────────────────────────────────────────────────

it('accepts only alpha-2 under the ALPHA2 format', function () {
    // The point of pinning a format is that the other two notations for the same
    // country stop being acceptable — otherwise the argument would be decorative.
    $rule = new Country(Country::ALPHA2);

    expect(countryRuleFailures($rule, 'US'))->toBe([])
        ->and(countryRuleFailures($rule, 'USA'))->toBe(['validation.country.invalid'])
        ->and(countryRuleFailures($rule, '840'))->toBe(['validation.country.invalid']);
});

it('accepts only alpha-3 under the ALPHA3 format', function () {
    $rule = new Country(Country::ALPHA3);

    expect(countryRuleFailures($rule, 'USA'))->toBe([])
        ->and(countryRuleFailures($rule, 'US'))->toBe(['validation.country.invalid'])
        ->and(countryRuleFailures($rule, '840'))->toBe(['validation.country.invalid']);
});

it('accepts only the numeric code under the NUMERIC format', function () {
    $rule = new Country(Country::NUMERIC);

    expect(countryRuleFailures($rule, '840'))->toBe([])
        ->and(countryRuleFailures($rule, 'US'))->toBe(['validation.country.invalid'])
        ->and(countryRuleFailures($rule, 'USA'))->toBe(['validation.country.invalid']);
});

it('rejects an unknown code under every pinned format', function () {
    expect(countryRuleFailures(new Country(Country::ALPHA2), 'XX'))->toBe(['validation.country.invalid'])
        ->and(countryRuleFailures(new Country(Country::ALPHA3), 'XXX'))->toBe(['validation.country.invalid'])
        ->and(countryRuleFailures(new Country(Country::NUMERIC), '999'))->toBe(['validation.country.invalid']);
});

// ─────────────────────────────────────────────────────────
//  Inputs that used to reach past the rule
// ─────────────────────────────────────────────────────────

it('fails a value that is not a string rather than breaking on it', function (mixed $value) {
    // These went straight into Symfony Intl, which is typed for strings, so a JSON body that
    // omitted the field or sent an array answered with a 500 instead of a validation failure.
    // Rejecting it is this rule's job; a `string` rule beside it is still worth having, but no
    // longer the difference between a message and a stack trace.
    expect(countryRuleFailures(new Country, $value))->toBe(['validation.country.invalid']);
})->with([
    'null' => [null],
    'int' => [840],
    'array' => [['US']],
    'bool' => [true],
]);

it('refuses an unknown format when the rule is built, not when a request arrives', function () {
    // Was an UnhandledMatchError raised on a user's form submission, from a typo made when the
    // ruleset was written. Phone already refused a bad format in its constructor; this now
    // agrees with it.
    expect(fn () => new Country('alpha-2'))
        ->toThrow(InvalidArgumentException::class, 'Unknown country format "alpha-2"');
});
