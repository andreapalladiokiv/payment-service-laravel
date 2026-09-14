<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Dispute;

use InvalidArgumentException;

/**
 * Where an operator answers a ConnexPay case: the portal URL for one case, built from a configured
 * base.
 *
 * ## Why the base URL is configuration and not a constant
 *
 * ConnexPay's reference carries the **API** base and no portal URL — the CMS host is
 * `https://cmsapi.connexpay.com`, and where an operator signs in is a fact about their web
 * application that no API document states. Inventing one from a plausible-looking hostname is worse
 * than having none: the operator clicks it, and a link that resolves to somebody else's host — or
 * to a 404 — costs a response window that does not come back. So the origin is supplied by a human,
 * and what this class owns is the *shape* the case's identity is carried in.
 *
 * **The variable to set is `services.connexpay.dispute_portal_url`** — a key in the same
 * `services.{gateway}` map the credential defaults come from, or, equivalently, the
 * `disputePortalUrl` / `dispute_portal_url` key on the credential row
 * ({@see \Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure::stringSetting()} reads
 * either spelling). Whichever route the application wires, what arrives here is the base, and a
 * blank one is refused at construction rather than producing a link that goes nowhere.
 *
 * ## Why it cannot live in the ConnexPay package, where the plan puts it
 *
 * F9's file list names `src/ConnexPay/src/Dispute/` for "the operator deep link". It cannot be
 * built there: this class's only consumer is the `DisputeActionsPort` implementation, which lives in
 * `src/Laravel/src/` because it must name `DisputeActionSet` and `DashboardDisputeAction` — the
 * action vocabulary is Domain, and a provider package may not name Domain types — and `Laravel` is
 * not allowed to reach the `ConnexPay` package (`tests/Arch/PackageHierarchyTest.php` mirrors
 * `src/Laravel/composer.json`). A class in `src/ConnexPay/` reached from `src/Laravel/` would fail
 * the Arch suite here and fatal in the split package, where the Laravel bridge does not depend on
 * that gateway at all. So the shape lives on the side that can use it; the deviation is the same
 * one §0.4 made for the port implementations themselves.
 *
 * ## The shape, and the one part of it nobody can source
 *
 * The link is `<base>?caseNumber=<CaseNumber>`, joined with `&` when the base already carries a
 * query. The case identity is the **case number**, which is the reference every other part of this
 * domain addresses a ConnexPay case by: the reference table holds it against our dispute aggregate
 * id, and the caller — the `DisputeActionsPort` implementation — resolves it from there, because the
 * aggregate does not carry the provider's reference. It is also what an operator reads off a case.
 * That is what makes the operator land on the case rather than on a search box.
 *
 * The parameter *name* is this project's own and is not sourced from anywhere — no ConnexPay
 * document describes the portal's query string. It is here, as {@see self::CASE_PARAMETER}, so that
 * whoever can look at the real portal corrects one constant instead of hunting the shape through an
 * adapter; the alternative — a base URL that carries the whole template — was rejected because then
 * the shape is unversioned configuration and nothing fails when it is wrong.
 */
final readonly class ConnexPayDisputePortalLink
{
    /**
     * The query parameter the case's own identity travels in.
     *
     * Named after ConnexPay's own field for it, which is the only spelling available to us.
     */
    public const string CASE_PARAMETER = 'caseNumber';

    /**
     * @param string $baseUrl the portal origin, from configuration — see the class docblock. Trailing
     *   slashes and an existing query string are both tolerated; a blank value is refused.
     */
    public function __construct(private string $baseUrl)
    {
        trim($baseUrl) !== '' || throw new InvalidArgumentException(
            'The ConnexPay dispute portal base URL is not configured. The link an operator answers a '
            .'case at is built from it, and no portal origin is stated anywhere ConnexPay documents — '
            .'set `services.connexpay.dispute_portal_url` (or the `disputePortalUrl` credential '
            .'setting) rather than letting this class guess a hostname an operator would then click.',
        );
    }

    /**
     * The link for one case.
     *
     * @param string $caseNumber the provider's own `CaseNumber` — the raw reference, never
     *   re-encoded by the caller: it is URI-encoded here, once, because a case number is the
     *   provider's value and not this project's, and one that arrived with a `+` or a space in it
     *   would otherwise truncate the link.
     */
    public function for(string $caseNumber): string
    {
        trim($caseNumber) !== '' || throw new InvalidArgumentException(
            'A portal link must name the case it opens. Without a case number the link is a search '
            .'box, which is the one thing this link exists not to be.',
        );

        return $this->baseUrl
            . (str_contains($this->baseUrl, '?') ? '&' : '?')
            . self::CASE_PARAMETER
            . '='
            . rawurlencode($caseNumber);
    }

    /** The configured base, as the deployment stated it. */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }
}
