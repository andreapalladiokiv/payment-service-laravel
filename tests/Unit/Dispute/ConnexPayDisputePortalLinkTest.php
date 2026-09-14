<?php

declare(strict_types=1);

use Techork\PaymentService\Laravel\Dispute\ConnexPayDisputePortalLink;

/**
 * The operator deep link: **the shape, pinned against whatever base the class is handed.**
 *
 * ## What is under test, and what deliberately is not
 *
 * The base URL is configuration — no ConnexPay document states where an operator signs in, and a
 * guessed hostname is a link that costs a response window when it 404s — so there is nothing to
 * assert about a real portal here. What is assertable is the part this class owns: the case's own
 * identity travels as one query parameter, URI-encoded once, appended to whatever base it was given
 * whether or not that base already carries a query. Every assertion below is that sentence.
 *
 * ## Why the shape is pinned against several bases rather than against one string
 *
 * One expected literal would pin the *join* and not the shape: a class that special-cased a trailing
 * slash, or that always emitted `?` and trusted the deployment to have none, would pass on the one
 * base the test happened to use. The dataset below therefore runs the same invariant over bases of
 * four different shapes, and the invariant is read back out of the constructed URL with
 * `parse_url()`/`parse_str()` rather than compared to a string this file built by hand — a test that
 * built the expected link the same way the class does would agree with the class and prove nothing.
 */

/** The case identity every link carries, with the characters that would truncate a raw link. */
function connexPayPortalCase(string $caseNumber = 'CB-1004'): string
{
    return $caseNumber;
}

/**
 * The shape, as a reader of the link sees it: the base it was handed, and the case it addresses.
 *
 * @return array{base: string, query: array<string, string>}
 */
function connexPayPortalShape(string $link, string $base): array
{
    $parsed = parse_url($link);

    expect($parsed)->toBeArray()
        ->and($parsed['scheme'] ?? null)->toBe('https')
        // The base is carried through untouched: whatever the deployment configured is what an
        // operator's browser is sent to, path and all.
        ->and($link)->toStartWith($base);

    parse_str((string) ($parsed['query'] ?? ''), $query);

    return ['base' => $link, 'query' => $query];
}

// ──────────────────────────────────────────────
//  the shape, over bases of four shapes
// ──────────────────────────────────────────────

/**
 * The invariant, over the base shapes a deployment can plausibly configure. Each one is a real link
 * an operator has to be able to click.
 */
it('carries the case as one encoded parameter on any base it is handed', function (string $base, array $expected) {
    $link = new ConnexPayDisputePortalLink($base)->for(connexPayPortalCase());

    $shape = connexPayPortalShape($link, $base);

    expect($shape['query'])->toBe($expected)
        // Exactly one of it, and the spelling the constant names — not a near miss and not two of
        // them, which a base that already carries a query would otherwise produce.
        ->and(substr_count((string) parse_url($link, PHP_URL_QUERY), ConnexPayDisputePortalLink::CASE_PARAMETER.'='))->toBe(1)
        ->and($shape['query'][ConnexPayDisputePortalLink::CASE_PARAMETER])->toBe('CB-1004');
})->with([
    // `?` when the base has no query, `&` when it has one: a base assembled by a deployment that
    // already put its own parameters on it still produces a link and not a second question mark.
    'a bare origin' => ['https://portal.connexpay.example', ['caseNumber' => 'CB-1004']],
    'a path with a trailing slash' => ['https://portal.connexpay.example/cases/', ['caseNumber' => 'CB-1004']],
    'a base that already carries a query' => ['https://portal.connexpay.example/cases?view=chargebacks', ['view' => 'chargebacks', 'caseNumber' => 'CB-1004']],
    'a base with a fragment-free deep path' => ['https://portal.connexpay.example/crm/chargebacks/list', ['caseNumber' => 'CB-1004']],
]);

/**
 * The two spellings worth an expected literal, because they are what a deployment will actually
 * configure and what a reviewer will actually read.
 */
it('joins the case onto the configured base, with & when the base already has a query', function () {
    expect((new ConnexPayDisputePortalLink('https://portal.connexpay.example/cases'))->for('CB-1004'))
        ->toBe('https://portal.connexpay.example/cases?caseNumber=CB-1004')
        ->and((new ConnexPayDisputePortalLink('https://portal.connexpay.example/cases?view=chargebacks'))->for('CB-1004'))
        ->toBe('https://portal.connexpay.example/cases?view=chargebacks&caseNumber=CB-1004');
});

/**
 * The case number is the provider's own value, so it is encoded exactly once, here. A `+` left raw
 * decodes as a space on the far side and a space left raw truncates the link at the browser — either
 * way the operator lands on a search box rather than on the case, which is the one thing this link
 * exists not to be.
 */
it('encodes a case number that would otherwise truncate the link', function () {
    $link = new ConnexPayDisputePortalLink('https://portal.connexpay.example/cases')->for('CB 1004+1');

    expect($link)->toBe('https://portal.connexpay.example/cases?caseNumber=CB%201004%2B1')
        // And it survives the round trip: what the portal reads back is the case number itself.
        ->and(connexPayPortalShape($link, 'https://portal.connexpay.example/cases')['query'])
        ->toBe(['caseNumber' => 'CB 1004+1']);
});

// ──────────────────────────────────────────────
//  the two refusals: a link to nowhere is worse than no link
// ──────────────────────────────────────────────

/**
 * A blank base is refused at construction, which is where the application wires this — at boot, not
 * at an operator's click. The message names the configuration key, because the human who has to fix
 * it is reading a boot failure rather than this test.
 */
it('refuses a blank base and names the setting to configure', function (string $base) {
    expect(fn () => new ConnexPayDisputePortalLink($base))
        ->toThrow(InvalidArgumentException::class, 'services.connexpay.dispute_portal_url');
})->with(['empty' => '', 'whitespace' => '   ', 'a newline' => "\n"]);

it('refuses a link that names no case', function (string $caseNumber) {
    $portal = new ConnexPayDisputePortalLink('https://portal.connexpay.example/cases');

    expect(fn () => $portal->for($caseNumber))
        ->toThrow(InvalidArgumentException::class, 'must name the case');
})->with(['empty' => '', 'whitespace' => '  ']);

/** The base is reported as the deployment stated it — nothing here normalises what it was handed. */
it('reports the base it was configured with', function () {
    expect((new ConnexPayDisputePortalLink('https://portal.connexpay.example/cases/'))->baseUrl())
        ->toBe('https://portal.connexpay.example/cases/');
});
