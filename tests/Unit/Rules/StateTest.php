<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Country as CountryVO;
use Techork\PaymentService\Common\ValueObject\State as StateVO;
use Techork\PaymentService\Laravel\Rules\State;

/**
 * Runs the rule against a whole payload, because `State` is a `DataAwareRule`:
 * the value it accepts depends on a sibling field, so there is nothing to test
 * without the surrounding data.
 *
 * @param  array<string, mixed>  $data
 * @return list<string>
 */
function stateRuleFailures(string $countryKey, array $data, mixed $value, string $attribute = 'state'): array
{
    $failures = [];
    $rule = (new State($countryKey))->setData($data);
    $rule->validate($attribute, $value, function (string $message) use (&$failures) {
        $failures[] = $message;
    });

    return $failures;
}

// ─────────────────────────────────────────────────────────
//  Countries with a defined state list
// ─────────────────────────────────────────────────────────

it('accepts a state code that belongs to the sibling country', function () {
    expect(stateRuleFailures('country', ['country' => 'US'], 'AK'))->toBe([]);
});

it('rejects a state code that is not in the sibling country list', function () {
    // The core boundary: 'XX' is not a US state.
    expect(stateRuleFailures('country', ['country' => 'US'], 'XX'))
        ->toBe(['The :attribute must be a valid state code for US.']);
});

it('rejects a code that is valid for a different country', function () {
    // The reason the rule is data-aware at all. 'ON' is an Ontario, not a US state;
    // a country-blind list check would wave it through.
    expect(stateRuleFailures('country', ['country' => 'CA'], 'ON'))->toBe([])
        ->and(stateRuleFailures('country', ['country' => 'US'], 'ON'))
        ->toBe(['The :attribute must be a valid state code for US.']);
});

it('names the country in the failure message so the user can see which list applied', function () {
    // With one message per country the error is actionable; the country is
    // interpolated, not the state, because the state is already the field's value.
    expect(stateRuleFailures('country', ['country' => 'CA'], 'AK'))
        ->toBe(['The :attribute must be a valid state code for CA.']);
});

it('compares codes case-sensitively', function () {
    // `in_array` is called in strict mode against the canonical uppercase codes, so
    // the rule does not normalise input. Pinned because a payload built from a
    // lowercase select option would be rejected, which is easy to mistake for a bug.
    expect(stateRuleFailures('country', ['country' => 'US'], 'ak'))
        ->toBe(['The :attribute must be a valid state code for US.']);
});

it('rejects a non-string value when a state list applies', function () {
    // Strict comparison also means null and ints never match a code, so a missing
    // state on a country that has states is a failure rather than a silent pass.
    expect(stateRuleFailures('country', ['country' => 'US'], null))
        ->toBe(['The :attribute must be a valid state code for US.'])
        ->and(stateRuleFailures('country', ['country' => 'US'], 42))
        ->toBe(['The :attribute must be a valid state code for US.']);
});

it('covers every country that defines a state list', function () {
    // Guards the mapping end to end rather than sampling one country: each list the
    // VO publishes has to be reachable through the rule, and its first code accepted.
    foreach (['AU', 'CA', 'IN', 'NZ', 'GB', 'US'] as $country) {
        $codes = array_map(strval(...), StateVO::all(new CountryVO($country)));

        expect($codes)->not->toBe([])
            ->and(stateRuleFailures('country', ['country' => $country], $codes[0]))->toBe([]);
    }
});

// ─────────────────────────────────────────────────────────
//  Countries without a defined state list
// ─────────────────────────────────────────────────────────

it('passes anything for a country that has no state list', function () {
    // Documented behaviour: the rule only knows six countries, so for the rest it
    // must abstain rather than reject every address in France.
    expect(stateRuleFailures('country', ['country' => 'FR'], 'whatever'))->toBe([])
        ->and(stateRuleFailures('country', ['country' => 'FR'], null))->toBe([]);
});

// ─────────────────────────────────────────────────────────
//  When the sibling country field is unusable
// ─────────────────────────────────────────────────────────

it('abstains when the country field is absent', function () {
    // The country field has its own rule; failing here too would double-report the
    // same mistake, and there is no list to check against anyway.
    expect(stateRuleFailures('country', [], 'XX'))->toBe([]);
});

it('abstains when the country is not a two-letter code', function () {
    // The state lists are keyed by alpha-2 only, so an alpha-3 or numeric country —
    // both of which the Country *rule* accepts — carries no list to compare against.
    expect(stateRuleFailures('country', ['country' => 'USA'], 'XX'))->toBe([])
        ->and(stateRuleFailures('country', ['country' => '840'], 'XX'))->toBe([])
        ->and(stateRuleFailures('country', ['country' => 'U'], 'XX'))->toBe([]);
});

it('abstains when the country is not a string at all', function () {
    expect(stateRuleFailures('country', ['country' => 42], 'XX'))->toBe([])
        ->and(stateRuleFailures('country', ['country' => null], 'XX'))->toBe([])
        ->and(stateRuleFailures('country', ['country' => ['US']], 'XX'))->toBe([]);
});

// ─────────────────────────────────────────────────────────
//  Locating the sibling field
// ─────────────────────────────────────────────────────────

it('finds the country through a dotted path', function () {
    // Address fields are nested in real payloads, which is why the lookup goes
    // through `data_get` rather than a plain array access.
    $data = ['billing' => ['country' => 'US', 'state' => 'AK']];

    expect(stateRuleFailures('billing.country', $data, 'AK'))->toBe([])
        ->and(stateRuleFailures('billing.country', $data, 'XX'))
        ->toBe(['The :attribute must be a valid state code for US.']);
});

it('abstains when the dotted path resolves to nothing', function () {
    expect(stateRuleFailures('billing.country', ['shipping' => ['country' => 'US']], 'XX'))->toBe([]);
});

it('returns itself from setData so it can be used fluently', function () {
    // The `DataAwareRule` contract only asks for the data to be stored; returning
    // `$this` is what lets the validator chain it, and callers rely on that.
    $rule = new State('country');

    expect($rule->setData(['country' => 'US']))->toBe($rule);
});
