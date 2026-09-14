<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeAggregateRepositoryInterface;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\ValueObject\AcceptDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DashboardDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceRequirements;
use Techork\PaymentService\Domain\Dispute\ValueObject\RespondDisputeAction;
use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\Contract\DisputeCaseReading;
use Techork\PaymentService\Gateway\Role\ReadsDisputeCases;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\DisputeActionsAdapter;
use Techork\PaymentService\Laravel\Port\DisputeCallRefused;
use Techork\PaymentService\Tests\Support\DisputePortCase;
use Techork\PaymentService\Tests\Support\DisputeReferenceTable;

/**
 * The action set a Stripe case comes back with, and the one requirement F2 puts on it: **an empty
 * set means "not waiting on us"**, never "this provider has no call".
 *
 * Stripe has both calls — evidence over `POST /v1/disputes/:id`, the concession over `/close` — so a
 * driver answering with nothing would be stating something false about every case it is asked
 * about. What makes the answer interesting is that "still waiting on us" has two halves and only one
 * of them is in the provider's payload: Stripe reports a case we already answered as
 * `needs_response` until it processes the response, and a case we conceded as `lost`, exactly as it
 * reports one the issuer decided. So the adapter reads the provider for its half and our own
 * aggregate for ours, and both directions are asserted here.
 *
 * ## Where the ceiling comes from, and what this file is really testing
 *
 * Which actions a case admits is not decided here: it is the case's own answer
 * ({@see DisputeAggregate::availableDisputeActions()}), and this adapter asks for it with the live
 * read's facts. Everything the adapter does afterwards is a **subtraction** — the provider has taken
 * the case back, our response is already filed, the provider will not take a close on this case —
 * and each of those has a test of its own below, because a subtraction that stopped happening would
 * otherwise be invisible: the set would simply be the ceiling's, and every "offers a response"
 * assertion in this file would still pass.
 *
 * The role is faked, the aggregate repository is faked and the reference table is in memory; no
 * driver and no HTTP client is in this file.
 *
 * ## Where the provider's reference comes from
 *
 * A question about a case of ours names our `DisputeId` and nothing else — the provider's own name
 * for the case lives in `gateway_references` now — so the adapter resolves the reference it addresses
 * the read with from the table the fixture hands it ({@see DisputePortCase::references()}). An
 * unattributable case is the other shape and needs no table: it carries the provider's reference
 * because that is literally all there is of it. The assertions on `disputeReference` below cover
 * both, which is why the fixture's two constructors are exercised rather than one being papered
 * over.
 */

/** A fake {@see ReadsDisputeCases}: the readings it was asked for, and the one it answers with. */
final class DisputePortReadsDisputeCases implements ReadsDisputeCases
{
    /** @var list<DisputeCaseQuery> every question this fake was asked, in order. */
    public array $queries = [];

    public function __construct(private readonly DisputeCaseReading|Throwable $answer) {}

    public function readDisputeCase(DisputeCaseQuery $query): DisputeCaseReading
    {
        $this->queries[] = $query;

        if ($this->answer instanceof Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}

/** A fake repository: the aggregate our own record answers with, or nothing at all. */
final class DisputePortDisputeRepository implements DisputeAggregateRepositoryInterface
{
    /** @var list<DisputeId> every case this fake was asked for. */
    public array $retrieved = [];

    public function __construct(private readonly ?DisputeAggregate $case = null) {}

    public function retrieve(DisputeId $aggregateRootId): DisputeAggregate
    {
        $this->retrieved[] = $aggregateRootId;

        return $this->case ?? throw new LogicException(
            'This fake holds no aggregate, so nothing should have asked it for one.',
        );
    }

    public function persist(DisputeAggregate $aggregateRoot): void {}
}

/**
 * @param  DisputeReferenceTable|null  $references  the row the provider's reference is read from.
 *   Null is the fixture's own table, holding the row for this case; an empty one is how a test says
 *   "we hold no row", which is deliberately not the same thing and must not quietly fall back to
 *   anything.
 */
function disputePortActions(
    DisputeCaseReading $reading,
    ?DisputeAggregate $case = null,
    ?DisputeAggregateRepositoryInterface $repository = null,
    ?DisputeReferenceTable $references = null,
): DisputeActionsAdapter {
    return new DisputeActionsAdapter(
        new DisputePortReadsDisputeCases($reading),
        $references ?? DisputePortCase::references(),
        GatewayId::generate(),
        $repository ?? ($case === null ? null : new DisputePortDisputeRepository($case)),
    );
}

// ──────────────────────────────────────────────
//  both actions, on a case that is waiting on us
// ──────────────────────────────────────────────

it('offers a response and a concession on a chargeback still waiting on us', function () {
    $reading = DisputePortCase::reading();
    $fake = new DisputePortReadsDisputeCases($reading);
    $adapter = new DisputeActionsAdapter(
        $fake,
        DisputePortCase::references(),
        GatewayId::generate(),
        new DisputePortDisputeRepository(DisputePortCase::open()),
    );

    $actions = $adapter->availableActions(DisputePortCase::actions(DisputePortCase::id()));
    $respond = $actions->get(RespondDisputeAction::class);
    $accept = $actions->get(AcceptDisputeAction::class);

    expect($actions->isEmpty())->toBeFalse()
        ->and($actions->actions())->toHaveCount(2)
        ->and($actions->has(DashboardDisputeAction::class))->toBeFalse()
        // The template is built from the pair the *provider* stated on the case, not from anything
        // the caller passed in: the response is the network's question, and answering a different
        // code's question is how a package comes back complete and incomplete at once.
        ->and($respond?->requirements)->toBeInstanceOf(EvidenceRequirements::class)
        ->and($respond?->requirements)->toEqual(EvidenceRequirements::tryFor(CardBrand::Visa, '10.4'))
        ->and($respond?->respondBy?->getTimestamp())
        ->toBe(DisputePortCase::at('2026-09-28T00:00:00+00:00')->getTimestamp())
        // The action carries the **ceiling** — what is at stake, so an operator can be shown the
        // number — and not the concession, which is chosen at the call.
        ->and($accept?->disputedAmount)->toEqual(new Money(25000, new Currency('USD')))
        ->and($accept?->respondBy?->getTimestamp())
        ->toBe(DisputePortCase::at('2026-09-28T00:00:00+00:00')->getTimestamp())
        // The provider is addressed with its own reference for the case, and our id is what let the
        // answer apply the half of the rule the provider cannot state.
        ->and($fake->queries)->toHaveCount(1)
        ->and($fake->queries[0]->disputeReference)->toBe(DisputePortCase::REFERENCE)
        ->and($fake->queries[0]->gatewayId->toString())->not->toBe('');
});

/**
 * The live read is newer than the last delivery we applied, and the window between deliveries is when
 * a case is lost — so the deadline on the action is the provider's, not the one our record holds. The
 * two are deliberately different in this fixture: an answer built from the record would look correct
 * and would hand an operator a window that has already moved.
 */
it('carries the provider’s own deadline, not the one our record holds', function () {
    $adapter = disputePortActions(
        DisputePortCase::reading(respondBy: DisputePortCase::at('2026-09-30T00:00:00+00:00')),
        DisputePortCase::open(),
    );

    $actions = $adapter->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->get(RespondDisputeAction::class)?->respondBy?->getTimestamp())
        ->toBe(DisputePortCase::at('2026-09-30T00:00:00+00:00')->getTimestamp())
        ->and($actions->get(AcceptDisputeAction::class)?->respondBy?->getTimestamp())
        ->toBe(DisputePortCase::at('2026-09-30T00:00:00+00:00')->getTimestamp())
        // Our own record still holds 2026-09-28, which is the point: the two disagree and the read
        // is the one that wins.
        ->and(DisputePortCase::open()->deadlineAt()?->getTimestamp())
        ->toBe(DisputePortCase::at('2026-09-28T00:00:00+00:00')->getTimestamp());
});

/**
 * The stage clause of the rule, reaching through the adapter: an inquiry is a case the provider is
 * waiting on, and one the concession is not documented for — `close` moves a chargeback to `lost`,
 * while an inquiry's closed status is an expiry. Offering the irreversible call on one would be
 * offering a call whose own answer we could not read back as ours.
 *
 * The read says the case is concedable in this fixture, because this is the case's own answer and not
 * the provider's: nothing in a Stripe payload distinguishes the stage for us, and the adapter must not
 * have to be told.
 *
 * **That pairing is not a contrived one, and it is the point of the fixture.** It is the input the
 * plan's revision block treats as reachable — `warning_needs_response` with `case_type: 'chargeback'`,
 * where ingestion records the stage from the status prefix (`inquiry`) while the read gates
 * `concedable` on the case type and answers `true`. The two deliberate readings collide, this adapter
 * subtracts and never adds, and the assertion below is what that resolves to: a response, no
 * concession, and the operator conceding in the dashboard instead.
 */
it('offers no concession on a case the rule withholds it from', function () {
    $adapter = disputePortActions(
        DisputePortCase::reading(concedable: true),
        DisputePortCase::open(stage: DisputeStage::Inquiry),
    );

    $actions = $adapter->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->isEmpty())->toBeFalse()
        ->and($actions->has(RespondDisputeAction::class))->toBeTrue()
        ->and($actions->has(AcceptDisputeAction::class))->toBeFalse();
});

/**
 * The subtraction, and the one that costs money if it stops happening: Stripe's own case type says a
 * close is not what this case is documented for — it is carrying an inquiry while our record reads a
 * chargeback — so the concession the rule admitted is withdrawn.
 *
 * Withheld is the safe direction on an irreversible call: an operator who needs to concede an inquiry
 * can still do it in Stripe's dashboard, while an action set offering a call the provider refuses is a
 * 400 in their face.
 */
it('withdraws the concession when the provider will not take one on this case', function () {
    $adapter = disputePortActions(
        DisputePortCase::reading(concedable: false),
        DisputePortCase::open(),
    );

    $actions = $adapter->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->isEmpty())->toBeFalse()
        ->and($actions->has(RespondDisputeAction::class))->toBeTrue()
        ->and($actions->has(AcceptDisputeAction::class))->toBeFalse();
});

/**
 * A template the table has no entry for leaves the action standing with a null template — never
 * dropped. The case is still waiting on us, still has a deadline, and still has to be surfaced;
 * null says the pair is not mapped or was not stated, not that the response needs nothing.
 *
 * The pair is the read's and not our record's, which is why nothing here falls back to the Visa `10.4`
 * the case was opened with: `reason()` is frozen at the opening delivery, so reading it would answer
 * the opening code's question while the live case is arguing a different one.
 */
it('keeps the response when the pair has no template, with the deadline attached', function () {
    $adapter = disputePortActions(
        DisputePortCase::reading(brand: 'diners', reasonCode: 'C08'),
        DisputePortCase::open(),
    );

    $actions = $adapter->availableActions(DisputePortCase::actions(DisputePortCase::id()));
    $respond = $actions->get(RespondDisputeAction::class);

    expect($respond?->requirements)->toBeNull()
        ->and($respond?->respondBy)->not->toBeNull()
        ->and($actions->has(AcceptDisputeAction::class))->toBeTrue();
});

// ──────────────────────────────────────────────
//  the empty set, and the two different ways to mean it
// ──────────────────────────────────────────────

/** The provider's half: it has taken the case under review or decided it. */
it('states nothing to do when the provider is no longer waiting on us', function () {
    $adapter = disputePortActions(
        DisputePortCase::reading(awaiting: false, concedable: false),
        DisputePortCase::open(),
    );

    $actions = $adapter->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->isEmpty())->toBeTrue()
        ->and($actions->actions())->toBe([]);
});

/**
 * Our half, and the case a reading alone gets wrong. Stripe keeps answering `needs_response` until
 * it processes the response we sent, so a set built from the provider's payload would offer a second
 * response on a case that already has one — and on the concession side that is an irreversible call
 * made twice.
 *
 * This is also the pinned divergence that forces the rule to ignore the submission axis: at
 * `(NeedsResponse, Submitted)` ConnexPay must answer **non-empty**, because there `SUBMITTED` is an
 * operator's word about a portal submission nobody confirmed. One pure query cannot carry two
 * answers, so the veto lives in the layer that knows how our filing travelled.
 */
it('states nothing to do when our own record says the response is already filed', function () {
    $case = DisputePortCase::answered();
    $repository = new DisputePortDisputeRepository($case);
    $adapter = disputePortActions(DisputePortCase::reading(), repository: $repository);

    $actions = $adapter->availableActions(DisputePortCase::actions(DisputePortCase::id()));

    expect($actions->isEmpty())->toBeTrue()
        // The provider said the case is still ours; our own record is what overruled it, and this
        // is the assertion that says so.
        ->and($repository->retrieved)->toHaveCount(1)
        ->and($repository->retrieved[0]->toString())->toBe(DisputePortCase::DISPUTE_ID);
});

it('states nothing to do when our own record says the case is decided', function () {
    $adapter = disputePortActions(
        DisputePortCase::reading(),
        DisputePortCase::open(DisputeStatus::Won),
    );

    expect($adapter->availableActions(DisputePortCase::actions(DisputePortCase::id()))->isEmpty())->toBeTrue();
});

/**
 * An unmatched case — the request names none of ours — answers from the provider's rules alone:
 * a response, carrying the deadline the read states.
 *
 * It offers **no concession**, and this is the assertion that inverts what this file used to say
 * ("it can only ever offer more than the aggregate would, never fewer"). An acceptance carries the
 * disputed amount so an operator can be shown what is at stake; the read states no amount at all, and
 * conceding a case whose price we do not know is the mistake the whole concession path is built to
 * prevent. So the unattributable answer is a deliberately smaller set rather than a widened one, and
 * refusing to answer would still be worse than either — it would leave a case nobody can see.
 */
it('answers a response and no concession when the case is not ours', function () {
    $adapter = disputePortActions(DisputePortCase::reading());

    $actions = $adapter->availableActions(DisputePortCase::actions());

    expect($actions->isEmpty())->toBeFalse()
        ->and($actions->has(RespondDisputeAction::class))->toBeTrue()
        ->and($actions->has(AcceptDisputeAction::class))->toBeFalse()
        // The template is still built from the read's pair: what is missing is the price, not the
        // argument.
        ->and($actions->get(RespondDisputeAction::class)?->requirements)
        ->toBeInstanceOf(EvidenceRequirements::class)
        ->toEqual(EvidenceRequirements::tryFor(CardBrand::Visa, '10.4'));
});

/**
 * The requirements table, looked up from the pair the read states.
 *
 * This is where the lookup is exercised now that a case of ours has its pair passed into the rule
 * instead of looked up here: the unattributable branch is the only one left that builds a template in
 * an adapter, and the ConnexPay path builds none at all — a link carries nothing else.
 *
 * A pair the table does not carry yields the action with **no template**, which is a statement about
 * the table and not about the case: "not written down yet" is not "nothing to do", and an action
 * dropped here would report a case about to be lost as one with nothing open on it.
 */
it('builds the unattributable template from the pair the provider states', function (?string $brand, ?string $reasonCode, bool $mapped) {
    $actions = disputePortActions(DisputePortCase::reading(brand: $brand, reasonCode: $reasonCode))
        ->availableActions(DisputePortCase::actions());

    $respond = $actions->get(RespondDisputeAction::class);

    expect($actions->isEmpty())->toBeFalse()
        // The template is what varies here, never the action: a pair we cannot read leaves the case
        // standing with its deadline.
        ->and($respond?->respondBy)->not->toBeNull()
        ->and($respond?->requirements !== null)->toBe($mapped);
})->with([
    'a pair in the table' => ['visa', '10.4', true],
    'a reason code the table does not carry' => ['amex', 'A01', false],
    'a pair the table does not carry' => ['mastercard', '10.4', false],
    // No brand at all cannot become a `CardBrand`, so there is no key to look the pair up by.
    'no brand on the case' => [null, '10.4', false],
]);

/**
 * A case of ours we hold no reference row for cannot be asked about at all, and the adapter must say
 * that rather than inventing a name for it.
 *
 * The request carries our `DisputeId` and the provider's own name for the case is what the read is
 * addressed with, so a missing row means we never recorded which case the provider knows. A
 * `RuntimeException`, deliberately **not** {@see DisputeCallRefused}: no provider refused anything,
 * and reporting our own gap as one would put a case the network never saw in front of an operator.
 * The two shapes are kept apart here as everywhere — an unattributable case still answers from the
 * provider's rules, because it carries a reference of the provider's and this one has none.
 */
it('throws rather than asking the provider about a case of ours it holds no row for', function () {
    $fake = new DisputePortReadsDisputeCases(DisputePortCase::reading());

    try {
        new DisputeActionsAdapter(
            $fake,
            new DisputeReferenceTable,
            GatewayId::generate(),
            new DisputePortDisputeRepository(DisputePortCase::open()),
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
 * The other unresolvable case of ours, and the sibling of the row above rather than a duplicate of
 * it: the reference row exists — the provider *can* be asked, and is — and the case it points at
 * cannot be read, because the caller wired no repository at all.
 *
 * That throws rather than answering from the provider's rules, for the reason a missing row does:
 * the request named a case of ours, so half of the answer is our own state, and the provider has not
 * been told anything about the half we are missing. An application assembling a screen for a case it
 * received no delivery for is not blocked by this — it asks about an **unattributable** case, which
 * carries the provider's own reference.
 */
it('throws rather than answering from the provider’s rules when our own record cannot be read', function () {
    $fake = new DisputePortReadsDisputeCases(DisputePortCase::reading());

    try {
        new DisputeActionsAdapter(
            $fake,
            DisputePortCase::references(),
            GatewayId::generate(),
        )->availableActions(DisputePortCase::actions(DisputePortCase::id()));
        $caught = null;
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught)->not->toBeInstanceOf(DisputeCallRefused::class)
        ->and($caught?->getMessage())->toContain(DisputePortCase::DISPUTE_ID)
        // The provider was asked — our row named the case, so the read was addressable — and no
        // answer of its own was allowed to stand in for the case's.
        ->and($fake->queries)->toHaveCount(1);
});
