<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use LogicException;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\Contract\DisputeAction;
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeAggregateRepositoryInterface;
use Techork\PaymentService\Domain\Dispute\Port\DisputeActionsPort;
use Techork\PaymentService\Domain\Dispute\Port\Request\AcceptDisputeRequest;
use Techork\PaymentService\Domain\Dispute\Port\Request\AvailableActionsRequest;
use Techork\PaymentService\Domain\Dispute\SubmissionState;
use Techork\PaymentService\Domain\Dispute\ValueObject\AcceptDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeActionSet;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceRequirements;
use Techork\PaymentService\Domain\Dispute\ValueObject\RespondDisputeAction;
use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\Contract\DisputeCaseReading;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Role\ReadsDisputeCases;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see DisputeActionsPort} backed by a gateway read, and by our own record of the case.
 *
 * ## Why Stripe implements this rather than answering with nothing
 *
 * Both operations this domain has are API calls at Stripe — evidence is filed over
 * `POST /v1/disputes/:id` and the case is conceded over `/close` — so a driver that answered with
 * no actions would be claiming a case is not waiting on us while its own API offers two ways to
 * answer it. F2 states the rule this class is written to: **an empty set means "not waiting on
 * us"**, never "this provider has no call". Paynet and Revolut, which have no dispute surface at
 * all, have no binding for this port for exactly that reason.
 *
 * ## The ceiling, and the three facts that narrow it
 *
 * Availability is stated once, by the case itself
 * ({@see DisputeAggregate::availableDisputeActions()}), and this class asks for it with the live
 * read's own facts — the deadline, the brand and the reason code — because the read is newer than the
 * last delivery we applied and the window between deliveries is when a case is lost. Everything this
 * class does afterwards **subtracts**, and each subtraction is a fact the aggregate cannot see:
 *
 *  - **`awaitingResponse` is false** — the provider has taken the case under review or decided it,
 *    and nothing here can offer an action on one;
 *  - **`concedable` is false** — Stripe's own case type says a close is not what this case is
 *    documented for, so the concession the ceiling admitted is withdrawn. Withheld is the safe
 *    direction on the one call in this domain that cannot be undone: an operator who needs to concede
 *    an inquiry can still do it in Stripe's dashboard, while an action set offering a call the
 *    provider refuses is a 400 in their face. This veto runs one way only, and its mirror image is
 *    recorded in the plan's revision block: at `warning_needs_response` with `case_type:
 *    'chargeback'` the read answers `true` while the ceiling's recorded stage (`Inquiry`) withholds
 *    the concession, and this class deliberately does not put it back — a provider read subtracts and
 *    never adds. That input is treated as reachable, not dismissed as a corner;
 *  - **`SUBMITTED` or `CONFIRMED` on the submission axis** — our response is already with the
 *    provider. The case reads `needs_response` until Stripe processes it, and an action set built
 *    from the read alone would offer a second response on a case that already has one, which on the
 *    accept side is an irreversible call taken twice.
 *
 * The submission veto is here and not in the rule because the same state means opposite things at two
 * providers: at Stripe it says an API call of ours filed the response, while at ConnexPay it records
 * an operator's word about a portal submission the portal never acknowledges, and the link has to
 * survive it. One state cannot carry two answers, so the veto sits in the layer that knows how our
 * filing travelled — the aggregate's own docblock says the same thing from its side.
 *
 * ## The unattributable case answers a response and no concession
 *
 * A case matching no aggregate of ours — named by the provider's own reference alone, per
 * `AvailableActionsRequest::unattributable()` — has no ceiling to consult and no recorded sum, so its
 * answer is the provider's own rules: a response, carrying the template and the deadline the read
 * states. It offers **no concession**, which is narrower than this class once answered. An
 * {@see AcceptDisputeAction} carries the disputed amount so an operator can be shown what is at
 * stake, the read states no amount at all, and conceding a case whose price we do not know is the
 * mistake the whole concession path is built to prevent. So this is the one place where the
 * unattributable answer is not the attributable one widened — it is a different set, deliberately a
 * smaller one, and it is worth saying that plainly rather than leaving a reader to infer it from
 * what the code happens not to build.
 *
 * ## Where the provider's reference comes from
 *
 * A case of ours is named by our aggregate id, and the reference the provider's read is addressed
 * with is read here, from `gateway_references` — the one place every provider reference in this
 * tree lives. A missing row is our own bookkeeping rather than the provider's answer, and it throws
 * a {@see RuntimeException} instead of reporting the case as having nothing open on it; nothing has
 * been asked of the provider at that point, so nothing it said could be the reason. The row is
 * written when the case is observed, by the recorder implementation (A0). An unattributable case
 * has no row and no id of ours, so the request's own `providerReference()` is the only name it has
 * — which is exactly the case the paragraph above is about.
 *
 * The same reasoning covers a case of ours whose aggregate cannot be read when it is needed: the
 * reference row exists and the case it points at does not, which is our bookkeeping disagreeing with
 * itself rather than anything the provider said. That throws too, for the reason
 * {@see self::aggregateFor()} gives.
 *
 * ## What each action carries
 *
 * Both are ours to make and carry no portal link: an operator link alongside an action this service
 * dispatches itself would invite a human to do by hand what the code was about to do correctly.
 * Neither is an authorisation — the case can move between this read and the call it suggests — so the
 * acting operations report their own outcome.
 *
 * A {@see RespondDisputeAction} carries the evidence template for the pair the provider stated on the
 * case, and null when it states none or the table has no entry for it. Null there is not "nothing is
 * needed": it is a case that still has to be surfaced, with its deadline and its action, while
 * somebody writes the requirements down ({@see EvidenceRequirements::tryFor()}). An
 * {@see AcceptDisputeAction} carries the disputed sum as its **ceiling** — what is at stake — and the
 * concession itself is chosen at the call ({@see AcceptDisputeRequest}).
 */
final readonly class DisputeActionsAdapter implements DisputeActionsPort
{
    /**
     * @param  ?DisputeAggregateRepositoryInterface  $disputes  where our own record of the case is
     *   read. Optional for a caller that only ever asks about unattributable cases — an application
     *   assembling a screen for a case it received no delivery for has no aggregate to hand in — but a
     *   request naming a case of ours without it throws rather than answering from the provider's
     *   rules, because availability is a statement about the case and not about the read.
     */
    public function __construct(
        private ReadsDisputeCases $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
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

        if (! $reading->awaitingResponse) {
            return DisputeActionSet::none();
        }

        // Unreachable: a reading that says the case is awaiting a response cannot exist without a
        // deadline, and the value object refuses to be built without one. Stated here rather than
        // assumed, because the alternative — dropping the action — would report a case as having
        // nothing open on the strength of a field the provider did not send.
        $respondBy = $reading->respondBy ?? throw new LogicException(sprintf(
            'The read of dispute "%s" reports the case is awaiting a response and carries no '
            .'deadline, which its own value object refuses to be constructed with.',
            $reference,
        ));

        $disputeId = $request->disputeId();

        if ($disputeId === null) {
            return DisputeActionSet::of([new RespondDisputeAction(
                requirements: self::requirementsFor($reading),
                respondBy: $respondBy,
            )]);
        }

        $aggregate = $this->aggregateFor($disputeId);

        if (! self::stillOpenAt($aggregate)) {
            return DisputeActionSet::none();
        }

        $offered = $aggregate->availableDisputeActions(
            respondBy: $respondBy,
            cardBrand: $reading->cardBrand === null ? null : CardBrand::tryFrom($reading->cardBrand),
            reasonCode: $reading->reasonCode,
        );

        return $reading->concedable ? $offered : self::withoutConcession($offered);
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
     * Our own record of the case the request names, which is where the ceiling is read from.
     *
     * A missing one is our bookkeeping rather than the provider's answer, and is thrown for the same
     * reason a missing reference row is: the request named a case of ours by our own id, so the
     * reference row and the stream behind it were both written by us. There is no reading of that
     * state in which the case has nothing open on it — the provider has not even been asked about the
     * half of the answer we are missing.
     */
    private function aggregateFor(DisputeId $disputeId): DisputeAggregate
    {
        return $this->disputes?->retrieve($disputeId) ?? throw new RuntimeException(sprintf(
            'No dispute aggregate is recorded for "%s", so what our own record leaves open on the '
            .'case is unknown. A case of ours that cannot be read is not a case with nothing open '
            .'on it.',
            $disputeId->toString(),
        ));
    }

    /**
     * Whether our own record still leaves this case open to us.
     *
     * Only the submission axis is consulted, and the status half this method used to carry has moved
     * into the ceiling: a case our record has decided is one the rule offers nothing on, so asking
     * here as well would be a second copy of one answer, free to drift from the first. What the rule
     * cannot carry is this — see the class docblock for why the submission veto is the adapter's and
     * not the aggregate's.
     */
    private static function stillOpenAt(DisputeAggregate $aggregate): bool
    {
        return ! in_array(
            $aggregate->submissionState(),
            [SubmissionState::Submitted, SubmissionState::Confirmed],
            true,
        );
    }

    /**
     * The ceiling with any concession taken out of it.
     *
     * A narrowing and never a widening, which is the whole of the model: the provider's own case type
     * is the live statement about whether a close is documented for this case, so it can withdraw a
     * concession the rule admitted — a case the rule reads as a chargeback that Stripe is carrying as
     * an inquiry — and it can never put one back on a case the rule refused it for.
     *
     * Filtered rather than answered with `none()`: withholding the concession says nothing about the
     * response, which is still open and must still be surfaced with its deadline.
     */
    private static function withoutConcession(DisputeActionSet $offered): DisputeActionSet
    {
        return DisputeActionSet::of(array_values(array_filter(
            $offered->actions(),
            static fn (DisputeAction $action): bool => ! $action instanceof AcceptDisputeAction,
        )));
    }

    /**
     * The evidence template for the pair the provider stated, or null where there is none to read.
     *
     * Used by the unattributable branch alone: a case of ours has its pair passed into the ceiling,
     * which builds the template there so that the rule for a response reads in one place. Both go
     * through the same lookup and the same argument — the brand is the provider's own string and only
     * a brand this project knows becomes a {@see CardBrand}, because a network we have no vocabulary
     * for has no requirements table to look up and mapping it onto a near neighbour would answer a
     * different network's question. The reason code is matched verbatim, for the reason the table is
     * keyed verbatim.
     */
    private static function requirementsFor(DisputeCaseReading $reading): ?EvidenceRequirements
    {
        $brand = $reading->cardBrand === null ? null : CardBrand::tryFrom($reading->cardBrand);

        return $brand === null || $reading->reasonCode === null
            ? null
            : EvidenceRequirements::tryFor($brand, $reading->reasonCode);
    }
}
