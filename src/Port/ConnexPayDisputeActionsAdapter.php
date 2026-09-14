<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
use RuntimeException;
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeAggregateRepositoryInterface;
use Techork\PaymentService\Domain\Dispute\Port\DisputeActionsPort;
use Techork\PaymentService\Domain\Dispute\Port\Request\AvailableActionsRequest;
use Techork\PaymentService\Domain\Dispute\ValueObject\AcceptDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DashboardDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeActionSet;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\Contract\DisputeCaseReading;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Role\ReadsDisputeCases;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Dispute\ConnexPayDisputePortalLink;

/**
 * {@see DisputeActionsPort} for ConnexPay: a case that is waiting on us is an operator's task,
 * carrying the portal link that answers it.
 *
 * ## Why this exists, and what would happen without it
 *
 * ConnexPay's CMS API is read-only — `GetByUser` and `GetByResolvedDate` and nothing that files a
 * response — so this is the provider F2's central rule was written for: an implementation that
 * answered with nothing would be saying "this case is not waiting on us" about every case the
 * gateway has ever had, while the response window kept running and the case was lost by default.
 * So the answer is a {@see DashboardDisputeAction} carrying a deep link, and it is **non-empty for
 * as long as the provider says the merchant is the party responsible for responding**.
 *
 * The sibling of {@see DisputeActionsAdapter}, which does the same job for Stripe; the two are
 * separate classes rather than one with a branch because almost nothing they do is shared. Stripe
 * offers API actions and consults our own record of the submission; ConnexPay offers one operator
 * action and does not — see below.
 *
 * ## The one action, and why the concession is not offered
 *
 * A {@see DashboardDisputeAction} carrying the portal link and nothing else. The ceiling admits an
 * {@see AcceptDisputeAction} on any case past the inquiry stage, and this adapter **drops it**,
 * deliberately:
 *
 *  - the reading's `concedable` is a statement about a *call this provider accepts* — at Stripe it
 *    is read off the case type — and ConnexPay has no call, so it says nothing here while the
 *    ceiling is asking about the case rather than about the API;
 *  - ConnexPay's case payload states nothing about whether the portal would take a concession on
 *    this case, and an acceptance is the one irreversible act in this domain: offering it on a
 *    guess is the same class of mistake as guessing the portal's hostname;
 *  - withholding costs nothing in practice. The link lands the operator **on the case**, from which
 *    conceding is a thing the portal either offers or does not — an operator who wants to give up
 *    the case is not blocked by a task queue that only names the response.
 *
 * ## The one place the model is neither a ceiling nor a subtraction
 *
 * Everywhere else in this model an adapter reads the case's answer and takes things out of it.
 * Here the response the ceiling offered is **replaced**: the case still has a response open on it,
 * which is exactly what the ceiling is consulted for, but the thing an operator can do about that
 * response is not the API call the ceiling names. So the consultation answers *whether* anything is
 * left, and the action that travels is this adapter's own — a link, in place of a call. Calling that
 * a subtraction would be saying the response was withdrawn, which is the opposite of true: this is
 * the provider whose cases would be lost by that reading, and the whole class exists because of it.
 *
 * ## Our own record, and the half of the rule this provider reads differently
 *
 * The provider's half comes from the read: `awaitingResponse` is false for a case the bank has, or
 * one resolved as a split or a general-ledger move, and nothing here offers an action on one. Our
 * half is the aggregate, consulted when the request names one, and it is consulted **through the
 * case's own answer** ({@see DisputeAggregate::availableDisputeActions()}) rather than through its
 * status field, so that "is there a response open on this case" has one author in this tree. The
 * status half that used to be read here directly said the same thing — a case our record has decided
 * offers nothing — and keeping both would be two copies of one answer, free to drift apart.
 *
 * `SUBMITTED` is **not** consulted, and this is where the two adapters part company. At Stripe
 * `SUBMITTED` means an API call of ours filed the response, so a second one must not be offered. At
 * ConnexPay it means an operator *says* they filed it in the portal — A3's task moves to "awaiting
 * confirmation" and closes only on `HasResponse: true` — and the case is still waiting on us, still
 * ticking, and still lost by default if the claim was wrong. Hiding the link then would take away
 * the only place the mistake can be discovered, exactly when it matters. That is the whole of F9's
 * rationale: a checkbox is not a confirmation.
 *
 * ## One coupling the ceiling brings, and why it is harmless here
 *
 * The ceiling is asked with the read's own deadline, and a case whose deadline neither the read nor
 * our record states answers nothing at all — the exception {@see DisputeActionSet} names, where an
 * empty set means "nothing is constructible" rather than "not waiting on us". Here that clause is
 * weaker than it needs to be: a {@see DashboardDisputeAction} carries no deadline, so a case with no
 * deadline anywhere would still have an operator task worth building.
 *
 * It is left as it is rather than special-cased, on reachability: a {@see DisputeCaseReading} refuses
 * to exist reporting `awaitingResponse` without a deadline, and the only writer of a null recorded
 * deadline is a backlog import (`OpenDisputeCommand`). Both have to fail at once. The alternative —
 * re-deriving "is a response open" here from the status alone — is the second copy of the rule this
 * class just gave up, and it would be wrong in the one direction that costs money: a case our record
 * has already decided is not one this adapter may offer a portal task on.
 *
 * ## Where the provider's reference comes from
 *
 * A case of ours is named by our aggregate id, and the `CaseNumber` the provider's read and the
 * portal link are addressed with is read here, from `gateway_references` — the one place every
 * provider reference in this tree lives. A missing row is our own bookkeeping rather than the
 * provider's answer, and it throws a {@see RuntimeException} instead of reporting the case as
 * having nothing open on it; nothing has been asked of the provider at that point, so nothing it
 * said could be the reason. The row is written when the case is observed, by the recorder
 * implementation (A0). An unattributable case has no row and no id of ours, so the request's own
 * `providerReference()` is the only name it has — which is exactly the case the paragraph above is
 * about.
 *
 * ## The unattributable case, which is not the attributable one widened
 *
 * A case matching no aggregate of ours — named by the provider's own reference alone per
 * `AvailableActionsRequest::unattributable()` — has no ceiling to consult, so its answer is the
 * provider's rules alone: a link, for as long as the read says the case is awaiting a response.
 * There is nothing narrower it could be, which is the opposite of the Stripe adapter's unattributable
 * answer and worth stating rather than leaving symmetric by assumption — this provider's one action
 * is the link, and an unattributable case has every part of it.
 *
 * ## The link is built here, per case, from a configured base
 *
 * {@see ConnexPayDisputePortalLink} owns the shape and the base URL comes from configuration — the
 * adapter names neither a hostname nor a query parameter. The case identity is the provider's
 * reference, which is `CaseNumber` throughout this domain.
 */
final readonly class ConnexPayDisputeActionsAdapter implements DisputeActionsPort
{
    /**
     * @param  ConnexPayDisputePortalLink  $portal  the deep link's shape and configured base. Built
     *   by whoever wires this adapter (A0) — a blank base is refused there, at boot, rather than at
     *   an operator's click.
     * @param  ?DisputeAggregateRepositoryInterface  $disputes  where our own record of the case is
     *   read, when the caller has one to hand in. Optional because the request may name a case that
     *   is not ours — an unattributable one — and because an application assembling a screen for it
     *   has no aggregate to give. Without it the answer is the provider's rules, and this class says
     *   so rather than guessing at our state.
     */
    public function __construct(
        private ReadsDisputeCases $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
        private ConnexPayDisputePortalLink $portal,
        private ?DisputeAggregateRepositoryInterface $disputes = null,
    ) {}

    #[Override]
    public function availableActions(AvailableActionsRequest $request): DisputeActionSet
    {
        $reference = $this->referenceFor($request);

        $reading = $this->gateway->readDisputeCase(new DisputeCaseQuery(
            gatewayId: $this->gatewayId,
            disputeReference: $reference,
        ));

        if (! $reading->awaitingResponse || ! $this->isStillOurs($request->disputeId(), $reading)) {
            return DisputeActionSet::none();
        }

        return DisputeActionSet::of([new DashboardDisputeAction($this->portal->for($reference))]);
    }

    /**
     * The provider's reference for the case this request names, however it names it.
     *
     * A case of ours is named by our aggregate id and the reference is read from our own table;
     * an unattributable case has no aggregate, no id and no row, so the provider's own reference
     * is the only name it has and it is what the request carries. Both branches are argued in the
     * class docblock; neither is a fallback for the other.
     */
    private function referenceFor(AvailableActionsRequest $request): string
    {
        $disputeId = $request->disputeId();

        if ($disputeId === null) {
            return $request->providerReference() ?? throw new RuntimeException(
                "An unattributable dispute request carries neither an aggregate id of ours nor "
                ."the provider's own reference, so the provider's read cannot be addressed.",
            );
        }

        $id = $disputeId->toString();

        return $this->transactionRepository->findForDispute($id)
            ?? throw new RuntimeException("No gateway transaction reference recorded for dispute '$id'.");
    }

    /**
     * Whether our own record still leaves this case open to us.
     *
     * True when the request names no case of ours — an unattributable case, which has no aggregate
     * to consult: see the class docblock for why that is the provider-rules-only answer rather
     * than a refusal to answer. True also when the request names one of ours but no aggregate can be
     * read for it, which is the same shape as unattributable from this side — an application
     * assembling a screen with no repository wired in — and is answered the same way rather than
     * refused, because the provider's read has already said the case is waiting on us.
     *
     * This is otherwise the case's own answer, asked with the deadline the read states, and never a
     * copy of it: the pair the read states is not passed in, because the action this class builds
     * carries no evidence template and asking for one would compute a value nobody reads.
     */
    private function isStillOurs(?DisputeId $disputeId, DisputeCaseReading $reading): bool
    {
        if ($disputeId === null) {
            return true;
        }

        $aggregate = $this->disputes?->retrieve($disputeId);

        return $aggregate === null
            || ! $aggregate->availableDisputeActions(respondBy: $reading->respondBy)->isEmpty();
    }
}
