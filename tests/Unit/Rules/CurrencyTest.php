<?php

declare(strict_types=1);

use Techork\PaymentService\Laravel\Rules\Currency;

/**
 * Collects what the rule handed to `$fail`; an empty list means accepted.
 *
 * The collector is variadic because this rule calls `$fail()` with two arguments —
 * a translation key plus a human-readable message. A fixed-arity closure would
 * turn a rejection into an ArgumentCountError, hiding the very outcome under test.
 *
 * @return list<array<int, mixed>>
 */
function currencyRuleFailures(Currency $rule, mixed $value, string $attribute = 'currency'): array
{
    $failures = [];
    $rule->validate($attribute, $value, function (...$args) use (&$failures) {
        $failures[] = $args;
    });

    return $failures;
}

// ─────────────────────────────────────────────────────────
//  Default: ISO and crypto together
// ─────────────────────────────────────────────────────────

it('accepts both an ISO and a crypto currency by default', function () {
    // The default constructor argument is the aggregate of both sets, so a payment
    // service can take fiat and crypto through one rule.
    expect(currencyRuleFailures(new Currency, 'USD'))->toBe([])
        ->and(currencyRuleFailures(new Currency, 'BTC'))->toBe([]);
});

it('rejects a code in neither set', function () {
    expect(currencyRuleFailures(new Currency, 'XYZ'))->toHaveCount(1);
});

it('reports the translation key and a message naming the attribute', function () {
    // Both arguments matter: the key is what a translated app renders, the message
    // is what surfaces when no translation exists.
    expect(currencyRuleFailures(new Currency, 'XYZ', 'billing.currency'))
        ->toBe([['validation.currency.invalid', 'billing.currency is not a valid currency.']]);
});

// ─────────────────────────────────────────────────────────
//  Narrowed sets: the boundary the types argument exists for
// ─────────────────────────────────────────────────────────

it('rejects crypto when only ISO currencies are allowed', function () {
    // The whole point of narrowing: a merchant configured for fiat must not be able
    // to open a BTC intent.
    $rule = new Currency([Currency::ISO]);

    expect(currencyRuleFailures($rule, 'USD'))->toBe([])
        ->and(currencyRuleFailures($rule, 'EUR'))->toBe([])
        ->and(currencyRuleFailures($rule, 'BTC'))->toHaveCount(1);
});

it('rejects fiat when only crypto currencies are allowed', function () {
    $rule = new Currency([Currency::CRYPTO]);

    expect(currencyRuleFailures($rule, 'BTC'))->toBe([])
        ->and(currencyRuleFailures($rule, 'ETH'))->toBe([])
        ->and(currencyRuleFailures($rule, 'USD'))->toHaveCount(1);
});

it('rejects everything when no currency type is allowed', function () {
    // An empty aggregate contains nothing. Pinned because `new Currency([])` reads
    // like "no restriction" but means the opposite.
    expect(currencyRuleFailures(new Currency([]), 'USD'))->toHaveCount(1);
});

// ─────────────────────────────────────────────────────────
//  Inherited from moneyphp/money — pinned as shape, not internals
// ─────────────────────────────────────────────────────────

it('accepts a lowercase code because Money upcases it', function () {
    // Unlike the Country rule, this one is case-insensitive — not by choice here but
    // because `Money\Currency` normalises its code. Pinned so the asymmetry between
    // the two rules is a recorded fact rather than a surprise.
    expect(currencyRuleFailures(new Currency, 'usd'))->toBe([]);
});

it('accepts XXX, the ISO code for "no currency"', function () {
    // ISO 4217 lists XXX and the ISO currency set includes it, so this rule cannot be
    // relied on to mean "a currency money can actually be moved in".
    expect(currencyRuleFailures(new Currency([Currency::ISO]), 'XXX'))->toBe([]);
});

it('rejects an empty code', function () {
    expect(currencyRuleFailures(new Currency, ''))->toHaveCount(1);
});

it('fails a value that is not a usable string rather than breaking on it', function (mixed $value) {
    // Fed straight to Money\Currency, which is typed for a non-empty string, so a null or an
    // array answered with a 500 where the caller asked for validation.
    expect(currencyRuleFailures(new Currency, $value))->toHaveCount(1);
})->with([
    'null' => [null],
    'array' => [['USD']],
    'int' => [840],
    'empty' => [''],
]);

it('refuses an unknown type when the rule is built, not when a request arrives', function () {
    // Was an UnhandledMatchError inside currencies() on a user's request, long after the typo
    // was written.
    expect(fn () => new Currency(['fiat']))
        ->toThrow(InvalidArgumentException::class, 'Unknown currency type "fiat"');
});
