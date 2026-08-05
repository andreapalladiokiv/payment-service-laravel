<?php

declare(strict_types=1);

use Techork\PaymentService\Laravel\Rules\Duration;

/**
 * Collects what the rule handed to `$fail`; an empty list means accepted.
 *
 * @return list<string>
 */
function durationRuleFailures(mixed $value, string $attribute = 'retention'): array
{
    $failures = [];
    (new Duration)->validate($attribute, $value, function (string $message) use (&$failures) {
        $failures[] = $message;
    });

    return $failures;
}

const DURATION_RULE_EXPECTED_MESSAGE = 'The retention must be a valid ISO 8601 duration (e.g. P14D).';

// ─────────────────────────────────────────────────────────
//  Accepted designators
// ─────────────────────────────────────────────────────────

it('accepts the day-based duration the message advertises', function () {
    // The example in the failure message has to be a value the rule actually takes.
    expect(durationRuleFailures('P14D'))->toBe([]);
});

it('accepts time-only and combined designators', function () {
    // `DateInterval` covers the whole grammar, not just days, and the rule adds no
    // restriction of its own — so a config value of PT30M is legitimate.
    expect(durationRuleFailures('PT30M'))->toBe([])
        ->and(durationRuleFailures('P1Y2M3DT4H5M6S'))->toBe([])
        ->and(durationRuleFailures('P1W'))->toBe([]);
});

// ─────────────────────────────────────────────────────────
//  The boundary: the P designator and its payload
// ─────────────────────────────────────────────────────────

it('rejects a duration with no leading P', function () {
    // The exact boundary the rule polices: '14D' is what a human writes and what
    // `DateInterval` refuses.
    expect(durationRuleFailures('14D'))->toBe([DURATION_RULE_EXPECTED_MESSAGE]);
});

it('rejects a bare P with no period after it', function () {
    // The other side of the same boundary — the designator alone is not a duration,
    // so the rule cannot be satisfied by the prefix.
    expect(durationRuleFailures('P'))->toBe([DURATION_RULE_EXPECTED_MESSAGE]);
});

it('rejects a lowercase designator', function () {
    // ISO 8601 designators are uppercase and the rule does not normalise, so 'p14d'
    // is a rejection rather than a silently accepted equivalent.
    expect(durationRuleFailures('p14d'))->toBe([DURATION_RULE_EXPECTED_MESSAGE]);
});

it('rejects an empty string and free text', function () {
    expect(durationRuleFailures(''))->toBe([DURATION_RULE_EXPECTED_MESSAGE])
        ->and(durationRuleFailures('two weeks'))->toBe([DURATION_RULE_EXPECTED_MESSAGE]);
});

it('rejects a datetime, which DateInterval also parses in other contexts', function () {
    // Guards against the rule being loosened to `strtotime`-style parsing: an
    // absolute date is not a duration, however readable it looks.
    expect(durationRuleFailures('2026-08-04'))->toBe([DURATION_RULE_EXPECTED_MESSAGE]);
});

// ─────────────────────────────────────────────────────────
//  Non-string input has its own branch
// ─────────────────────────────────────────────────────────

it('rejects a non-string value without letting DateInterval see it', function () {
    // The explicit `is_string` guard is why this is a validation failure rather than
    // a TypeError — the difference between a 422 and a 500 for a JSON body that sent
    // a number, and the reason the guard must not be removed as redundant.
    expect(durationRuleFailures(14))->toBe([DURATION_RULE_EXPECTED_MESSAGE])
        ->and(durationRuleFailures(null))->toBe([DURATION_RULE_EXPECTED_MESSAGE])
        ->and(durationRuleFailures(['P14D']))->toBe([DURATION_RULE_EXPECTED_MESSAGE])
        ->and(durationRuleFailures(new DateInterval('P14D')))->toBe([DURATION_RULE_EXPECTED_MESSAGE]);
});

it('fails exactly once per value', function () {
    // Both branches return immediately after failing; a fall-through would report the
    // same field twice.
    expect(durationRuleFailures(14))->toHaveCount(1)
        ->and(durationRuleFailures('14D'))->toHaveCount(1);
});

it('interpolates the attribute name into the message', function () {
    // The message is built with the raw attribute rather than Laravel's `:attribute`
    // placeholder, so the caller sees the field name it passed in.
    expect(durationRuleFailures('nope', 'trial_period'))
        ->toBe(['The trial_period must be a valid ISO 8601 duration (e.g. P14D).']);
});
