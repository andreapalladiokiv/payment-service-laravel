<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeAggregateRepositoryInterface;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\SubmissionState;
use Techork\PaymentService\Domain\Dispute\ValueObject\AcceptDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DashboardDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\RespondDisputeAction;
use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\Contract\DisputeCaseReading;
use Techork\PaymentService\Gateway\Role\ReadsDisputeCases;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Dispute\ConnexPayDisputePortalLink;
use Techork\PaymentService\Laravel\Port\ConnexPayDisputeActionsAdapter;
use Techork\PaymentService\Laravel\Port\DisputeCallRefused;
use Techork\PaymentService\Tests\Support\DisputePortCase;
use Techork\PaymentService\Tests\Support\DisputeReferenceTable;

/**
 * The ConnexPay action set: **a non-empty set carrying a portal link, for as long as the case is
 * waiting on us.**
 *
 * ## Why this file exists
 *
 * ConnexPay's CMS API is read-only, so F2's central rule — an empty set means "this dispute is not
 * waiting on us", never "this provider has no call" — is the whole of F9 here. An implementation
 * that answered with nothing would be making that false statement about every case the gateway has
 * ever had, while the response window kept running. The first assertion below is therefore the
 * task's done-condition, and the rest are the ways it could be true for the wrong reason: a link that
 * does not land on the case, an action offered on a case that is not ours, or an action withheld from
 * one that is.
 *
 * ## The one place the model is neither a ceiling nor a subtraction
 *
 * Everywhere else an adapter reads the case's own answer and takes things out of it. Here the answer
 * is **consulted for whether anything is left**, and then the action that travels is this adapter's
 * own: a link, in place of the API call the case named. The case still has a response open on it —
 * which is exactly what is being checked — and the thing an operator can do about it is not a call
 * this service makes. Two consequences are asserted below rather than left implicit: the concession
 * is dropped even where the case admits it, and the link is offered on an inquiry, where the case
 * admits a response and no concession.
 *
 * ## No HTTP and no driver
 *
 * {@see ReadsDisputeCases} is faked, our own repository is faked, the reference table is in memory,
 * and the deep link is the real {@see ConnexPayDisputePortalLink} with a configured base — the shape
 * is pinned in its own test and this one only has to show what the adapter does with it.
 *
 * ## Where the provider's reference comes from
 *
 * The read is addressed by the provider's `CaseNumber`, which for a case of ours is no longer on the
 * aggregate: the request names our `DisputeId` and the adapter resolves the provider's name for the
 * case out of `gateway_references` — the fixture's table, holding the row
 * ({@see DisputePortCase::references()}). An unattributable case carries the provider's reference
 * itself, because that is all there is of it, and needs no table at all. Both shapes are exercised
 * below, which is why the link assertion on `caseNumber=` is a statement about the resolved row and
 * not about a string the caller passed in.
 *
 * Helpers are prefixed `connexPayActions…`: Pest helpers are global for the whole suite.
 */

/** A fake {@see ReadsDisputeCases}: the questions it was asked, and the reading it answers with. */
final class ConnexPayActionsReadsDisputeCases implements ReadsDisputeCases
{
    /** @var list<DisputeCaseQuery> */
    public array $queries = [];

    public function __construct(private readonly DisputeCaseReading $answer) {}

    public function readDisputeCase(DisputeCaseQuery $query): DisputeCaseReading
    {
        $this->queries[] = $query;

        return $this->answer;
    }
}

/** A fake repository: our own record of the case, or nothing at all. */
final class ConnexPayActionsRepository implements DisputeAggregateRepositoryInterface
{
    /** @var list<DisputeId> */
    public array $retrieved = [];

    public function __construct(private readonly DisputeAggregate $case) {}

    public function retrieve(DisputeId $aggregateRootId): DisputeAggregate
    {
        $this->retrieved[] = $aggregateRootId;

        return $this->case;
    }

    public function persist(DisputeAggregate $aggregateRoot): void {}
}

/** The portal base a deployment configures. Never a literal in the class under test. */
const CONNEXPAY_ACTIONS_PORTAL_BASE = 'https://portal.connexpay.example/cases';

function connexPayActionsPortal(): ConnexPayDisputePortalLink
{
    return new ConnexPayDisputePortalLink(CONNEXPAY_ACTIONS_PORTAL_BASE);
}

/**
 * @param  ConnexPayActionsRepository|null  $repository  our own record. Null is the unmatched case:
 *   an application assembling a screen for a case with no aggregate of ours has nothing to hand in,
 *   and the adapter answers from the provider's rules alone.
 * @param  DisputeReferenceTable|null  $references  the row the provider's reference is read from —
 *   the fixture's table by default; an empty one is how a test says "we hold no row", which is
 *   deliberately not the same thing and must not quietly fall back to anything.
 */
function connexPayActionsAdapter(
    DisputeCaseReading $reading,
    ?ConnexPayActionsRepository $repository = null,
    ?DisputeReferenceTable $references = null,
): ConnexPayDisputeActionsAdapter {
    return new ConnexPayDisputeActionsAdapter(
        new ConnexPayActionsReadsDisputeCases($reading),
        $references ?? DisputePortCase::references(),
        GatewayId::generate(),
        connexPayActionsPortal(),
        $repository,
    );
}

// ──────────────────────────────────────────────
//  the done-condition: a non-empty set carrying the portal link
// ──────────────────────────────────────────────

it('offers the operator a portal link on a case the merchant must answer', function () {
    $reading = DisputePortCase::reading(concedable: false);
    $fake = new ConnexPayActionsReadsDisputeCases($reading);
    $adapter = new ConnexPayDisputeActionsAdapter(
        $fake,
        DisputePortCase::references(),
        GatewayId::generate(),
        connexPayActionsPortal(),
        new ConnexPayActionsRepository(DisputePortCase::open()),
    );

    $actions = $adapter->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->isEmpty())->toBeFalse()
        ->and($actions->actions())->toHaveCount(1)
        // The whole point of the set: there is something for a human to do, and the place to do it
        // is ConnexPay's portal — the only surface this provider gives us.
        ->and($actions->has(DashboardDisputeAction::class))->toBeTrue()
        // And nothing this service can execute itself. ConnexPay has no submission call, so an API
        // action here would be a call that does not exist.
        ->and($actions->has(RespondDisputeAction::class))->toBeFalse()
        // The link lands on *this* case rather than on a search box: the provider's own reference is
        // what travels in it.
        ->and($actions->get(DashboardDisputeAction::class)?->dashboardUrl)
        ->toContain('caseNumber='.DisputePortCase::REFERENCE)
        ->and($fake->queries)->toHaveCount(1)
        ->and($fake->queries[0]->disputeReference)->toBe(DisputePortCase::REFERENCE);
});

/**
 * The load-bearing case, and the one that parts company with the Stripe adapter: **a case whose
 * response we have already filed still gets the link.**
 *
 * At ConnexPay `SUBMITTED` records an operator's word — the portal tells us nothing back, and
 * `HasResponse` closing the case is the only confirmation there is. Hiding the link then would take
 * the one place the mistake can be discovered away from the person who made it, exactly while the
 * window is still running. At Stripe the same state means an API call of ours filed the response,
 * and a second one is money. This is the pair of assertions — here and in the Stripe file — that
 * forces the case's own rule to ignore the submission axis: one pure query cannot carry two answers,
 * so the veto lives in the layer that knows how our filing travelled.
 */
it('still offers the link on a case whose response we have already filed', function () {
    $case = DisputePortCase::answered();

    expect($case->submissionState())->toBe(SubmissionState::Submitted);

    $actions = connexPayActionsAdapter(DisputePortCase::reading(concedable: false), new ConnexPayActionsRepository($case))
        ->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->isEmpty())->toBeFalse()
        ->and($case->status())->toBe(DisputeStatus::NeedsResponse)
        ->and($actions->get(DashboardDisputeAction::class)?->dashboardUrl)
        ->toContain('caseNumber='.DisputePortCase::REFERENCE);
});

/**
 * The concession is dropped, and this is the assertion that keeps that from being an accident of the
 * code above it. The case *does* admit one — this fixture is a chargeback, past the inquiry stage —
 * and ConnexPay still gets none: its CMS API has no call to close a case, no case payload states
 * whether the portal would take one, and offering an irreversible act on a guess is the same class of
 * mistake as guessing the portal's hostname. The link lands the operator on the case, from which
 * conceding is a thing the portal either offers or does not.
 */
it('drops the concession the case admits, offering neither a call nor a separate task', function () {
    $case = DisputePortCase::open();

    // The case admits it: the ceiling this adapter consults is a chargeback's, so the drop below is
    // this adapter's decision and not the rule's.
    expect($case->availableDisputeActions(respondBy: DisputePortCase::at())->has(AcceptDisputeAction::class))
        ->toBeTrue();

    $actions = connexPayActionsAdapter(DisputePortCase::reading(concedable: false), new ConnexPayActionsRepository($case))
        ->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->has(AcceptDisputeAction::class))->toBeFalse()
        ->and($actions->actions())->toHaveCount(1);
});

/**
 * An inquiry is the stage the rule withholds the concession from, and the link is still the answer
 * here: the case admits a response, and a response is work for a human. Nothing about the
 * substitution depends on which actions the case named — only on whether it named any.
 */
it('offers the link on an inquiry, where the case admits a response and no concession', function () {
    $actions = connexPayActionsAdapter(
        DisputePortCase::reading(concedable: false),
        new ConnexPayActionsRepository(DisputePortCase::open(stage: DisputeStage::Inquiry)),
    )->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->isEmpty())->toBeFalse()
        ->and($actions->has(DashboardDisputeAction::class))->toBeTrue();
});

// ──────────────────────────────────────────────
//  the empty set, which is a statement about the case and not about the provider
// ──────────────────────────────────────────────

/**
 * `awaitingResponse: false` is the provider saying the case is with the bank, or decided, or was
 * never challengeable — the one reading that legitimately yields nothing. What is absent is any work
 * for us.
 */
it('answers with nothing when the provider is not waiting on us', function () {
    $actions = connexPayActionsAdapter(DisputePortCase::reading(awaiting: false, concedable: false), new ConnexPayActionsRepository(DisputePortCase::open()))
        ->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->isEmpty())->toBeTrue()
        ->and($actions->actions())->toBe([]);
});

/**
 * Our half of the rule: a case our own record has already taken out of `NEEDS_RESPONSE` — won, lost,
 * expired, conceded — offers nothing, whatever the provider's fields still say. ConnexPay keeps
 * reporting a decided case as `ResolutionTo: M` until its own resolution lands, and acting on that
 * would be acting on a case that is closed.
 *
 * This half now comes through the case's own answer rather than from a status comparison written
 * here, so what the assertion pins is that the consultation happens at all: a set built from the
 * reading alone would be non-empty on every one of these.
 */
it('answers with nothing when our own record has decided the case', function (DisputeStatus $status) {
    $actions = connexPayActionsAdapter(DisputePortCase::reading(concedable: false), new ConnexPayActionsRepository(DisputePortCase::open($status)))
        ->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->isEmpty())->toBeTrue();
})->with([
    'won' => DisputeStatus::Won,
    'lost' => DisputeStatus::Lost,
    'expired' => DisputeStatus::Expired,
    'closed' => DisputeStatus::Closed,
]);

// ──────────────────────────────────────────────
//  the unmatched case
// ──────────────────────────────────────────────

/**
 * A case matching no aggregate of ours: the caller has no id to give and no repository to consult,
 * so the answer is the provider's rules alone. Here that is the **whole** answer and not a narrowed
 * one, which is the opposite of the Stripe adapter's unattributable case — this provider's one action
 * is the link, and an unattributable case has every part of it. It is worth saying because the two
 * adapters are otherwise read as siblings.
 */
it('answers from the provider alone for a case we hold no record of', function () {
    $actions = connexPayActionsAdapter(DisputePortCase::reading(concedable: false))
        ->availableActions(DisputePortCase::actions());

    expect($actions->isEmpty())->toBeFalse()
        ->and($actions->has(DashboardDisputeAction::class))->toBeTrue();
});

/**
 * The same request shape — a case of ours — with no repository wired in, which is a caller that has
 * not assembled our own record at all.
 *
 * Answered from the provider's rules rather than refused, and this is a deliberate divergence from the
 * Stripe adapter, which throws. Both are answering the same absence; what differs is what the answer
 * would cost. At Stripe the half that cannot be read is "our response is already filed", so answering
 * anyway offers an irreversible call on a case that already had one. Here the half that cannot be read
 * is "our record has decided this case", and the action that would be offered in ignorance of it is a
 * **link** — a thing an operator clicks and then reads for themselves, on a case the provider says is
 * still waiting on us. Withholding it costs the case, and offering it costs a person a look.
 */
it('answers a case of ours from the provider alone when no repository is wired in', function () {
    $actions = connexPayActionsAdapter(DisputePortCase::reading(concedable: false))
        ->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->isEmpty())->toBeFalse()
        ->and($actions->has(DashboardDisputeAction::class))->toBeTrue();
});

/**
 * A case of ours we hold no reference row for cannot be asked about at all — and the whole reason
 * the two shapes of the request are separate constructors is that this must not be confused with the
 * unattributable case above, which answers happily because it carries a reference of the provider's.
 *
 * A missing row means we never recorded which case the provider knows, so nothing is read and no
 * link is built. A `RuntimeException`, deliberately **not** {@see DisputeCallRefused}: the provider
 * refused nothing, and a task queue entry naming a case that was never read is worse than an error
 * an operator can see.
 */
it('throws rather than asking the provider about a case of ours it holds no row for', function () {
    $fake = new ConnexPayActionsReadsDisputeCases(DisputePortCase::reading(concedable: false));

    try {
        new ConnexPayDisputeActionsAdapter(
            $fake,
            new DisputeReferenceTable,
            GatewayId::generate(),
            connexPayActionsPortal(),
            new ConnexPayActionsRepository(DisputePortCase::open()),
        )->availableActions(DisputePortCase::actions(DisputePortCase::id()));
        $caught = null;
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught)->not->toBeInstanceOf(DisputeCallRefused::class)
        ->and($caught?->getMessage())->toContain(DisputePortCase::DISPUTE_ID)
        // Nothing fell back to anything: the provider was never asked, and our own record was never
        // consulted either.
        ->and($fake->queries)->toBe([]);
});

/**
 * A case the provider is waiting on carries a deadline, so the case's own answer can never be empty
 * here for want of one — {@see DisputeCaseReading} refuses to be constructed with `awaitingResponse:
 * true` and no `respondBy`, and it is that refusal the adapter's consultation rests on.
 *
 * This assertion is the reading's own invariant, checked from the side that would have to be wrong for
 * a case to be reported as having nothing open on the strength of a missing deadline.
 */
it('cannot be handed an awaiting reading with no deadline', function () {
    expect(fn () => new DisputeCaseReading(awaitingResponse: true, concedable: false, cardBrand: 'visa', reasonCode: '10.4', respondBy: null))
        ->toThrow(InvalidArgumentException::class);
});
