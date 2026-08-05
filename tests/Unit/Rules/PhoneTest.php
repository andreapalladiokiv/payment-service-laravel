<?php

declare(strict_types=1);

use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Techork\PaymentService\Laravel\Rules\Phone;

/**
 * Runs the rule and collects what it handed to `$fail`, which is the only
 * observable output of a Laravel `ValidationRule`. An empty list means accepted.
 *
 * `$fail` is variadic on purpose: rules in this package sometimes pass a second
 * argument (a human-readable message beside the translation key), and a collector
 * with a fixed arity would turn that into an ArgumentCountError instead of a
 * validation result.
 *
 * @return list<string>
 */
function phoneRuleFailures(Phone $rule, mixed $value, string $attribute = 'phone'): array
{
    $failures = [];
    $rule->validate($attribute, $value, function (string $message) use (&$failures) {
        $failures[] = $message;
    });

    return $failures;
}

// ─────────────────────────────────────────────────────────
//  No format: parseability is the only bar
// ─────────────────────────────────────────────────────────

it('accepts an E164 number when no format is required', function () {
    expect(phoneRuleFailures(new Phone, '+19074861000'))->toBe([]);
});

it('accepts any parseable rendering when no format is required', function () {
    // The formatless rule is deliberately loose: punctuation and spacing are the
    // library's problem, not the caller's. This is the difference between the
    // default rule and `new Phone(Phone::E164)`.
    expect(phoneRuleFailures(new Phone, '+1 907-486-1000'))->toBe([])
        ->and(phoneRuleFailures(new Phone, 'tel:+1-907-486-1000'))->toBe([])
        ->and(phoneRuleFailures(new Phone, '+44 20 7183 8750'))->toBe([]);
});

it('rejects a value the library cannot parse at all', function () {
    expect(phoneRuleFailures(new Phone, 'not-a-phone'))->toBe(['validation.phone.invalid']);
});

it('rejects a national number with no country code', function () {
    // The boundary the rule exists to police: `parse()` is called without a default
    // region, so a bare national number is unresolvable and must be refused rather
    // than silently assumed to be American.
    expect(phoneRuleFailures(new Phone, '9074861000'))->toBe(['validation.phone.invalid']);
});

it('rejects an empty value', function () {
    expect(phoneRuleFailures(new Phone, ''))->toBe(['validation.phone.invalid'])
        ->and(phoneRuleFailures(new Phone, null))->toBe(['validation.phone.invalid']);
});

it('rejects a number whose country calling code does not exist', function () {
    expect(phoneRuleFailures(new Phone, '+999999999999'))->toBe(['validation.phone.invalid']);
});

it('casts non-string input before parsing rather than rejecting it outright', function () {
    // `(string) $value` means an int gets a chance; it still fails, but through the
    // parse path, so the failure key is the same one a caller can translate.
    expect(phoneRuleFailures(new Phone, 9074861000))->toBe(['validation.phone.invalid']);
});

// ─────────────────────────────────────────────────────────
//  Format-pinned mode: the value must already be in that shape
// ─────────────────────────────────────────────────────────

it('accepts an E164 value under the E164 format', function () {
    expect(phoneRuleFailures(new Phone(Phone::E164), '+19074861000'))->toBe([]);
});

it('rejects a parseable value that is not in the required format', function () {
    // The second boundary: parseable but differently rendered. This is what makes
    // the rule usable as a storage-shape guard rather than just a sanity check.
    expect(phoneRuleFailures(new Phone(Phone::E164), '+1 907-486-1000'))
        ->toBe(['validation.phone.invalid_format']);
});

it('reports the parse failure, not the format failure, when the value is unparseable', function () {
    // Order matters for the caller's error message: an unparseable value never
    // reaches the format comparison.
    expect(phoneRuleFailures(new Phone(Phone::E164), 'garbage'))
        ->toBe(['validation.phone.invalid']);
});

it('accepts the internationally-rendered formats when the value is in exactly that shape', function () {
    // Compared against the library's own output rather than hardcoded literals: the
    // international rendering comes from libphonenumber metadata, which is versioned
    // separately from this repo. The contract being pinned is "the rule accepts
    // precisely what the library formats", which is stable.
    $util = PhoneNumberUtil::getInstance();
    $parsed = $util->parse('+19074861000');

    expect(phoneRuleFailures(new Phone(Phone::INTERNATIONAL), $util->format($parsed, PhoneNumberFormat::INTERNATIONAL)))->toBe([])
        ->and(phoneRuleFailures(new Phone(Phone::RFC3966), $util->format($parsed, PhoneNumberFormat::RFC3966)))->toBe([]);
});

it('refuses the NATIONAL format when the rule is built, because it can match nothing', function () {
    // Was a characterization of a dead constant. `parse()` is called with no default region, so
    // a nationally-rendered value never parses, while anything that does parse carries a
    // country code and cannot equal the national rendering — the constant rejected every
    // possible input and a field wired to it was unfillable, silently.
    //
    // Now it says so at construction. Supporting it needs a region threaded through this rule,
    // which is a deliberate change rather than something to default into.
    expect(fn () => new Phone(Phone::NATIONAL))
        ->toThrow(RuntimeException::class, 'cannot be validated without a default region');
});

it('still accepts the formats that can be satisfied', function (string $format) {
    // The guard above must not have taken the working configurations with it.
    expect(fn () => new Phone($format))->not->toThrow(RuntimeException::class);
})->with([Phone::E164, Phone::INTERNATIONAL, Phone::RFC3966, '']);

it('rejects an E164 value under the international format and vice versa', function () {
    // Guards against the formats being wired to the wrong PhoneNumberFormat case,
    // which the accept-only tests above could not detect.
    $util = PhoneNumberUtil::getInstance();
    $international = $util->format($util->parse('+19074861000'), PhoneNumberFormat::INTERNATIONAL);

    expect(phoneRuleFailures(new Phone(Phone::INTERNATIONAL), '+19074861000'))
        ->toBe(['validation.phone.invalid_format'])
        ->and(phoneRuleFailures(new Phone(Phone::E164), $international))
        ->toBe(['validation.phone.invalid_format']);
});

// ─────────────────────────────────────────────────────────
//  Constructor contract
// ─────────────────────────────────────────────────────────

it('treats an empty format string as "no format required"', function () {
    // The default. Worth pinning because '' is a match arm, not a null check —
    // deleting that arm would turn every default-constructed rule into a throw.
    expect(phoneRuleFailures(new Phone(''), '+1 907-486-1000'))->toBe([]);
});

it('accepts format names case-insensitively', function () {
    // Format names reach this constructor from config and route definitions, where
    // casing is not controlled; `strtolower` is what makes that safe.
    expect(phoneRuleFailures(new Phone('E164'), '+19074861000'))->toBe([])
        ->and(phoneRuleFailures(new Phone('RFC3966'), '+19074861000'))
        ->toBe(['validation.phone.invalid_format']);
});

it('refuses an unknown format at construction time, not at validation time', function () {
    // Failing in the constructor means a typo surfaces when the ruleset is built,
    // rather than as a rejected payment for a value that was actually fine.
    expect(fn () => new Phone('e-164'))->toThrow(RuntimeException::class, 'Invalid format');
});
