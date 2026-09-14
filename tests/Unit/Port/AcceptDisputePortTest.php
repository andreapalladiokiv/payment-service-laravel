<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\Event\DisputeAccepted;
use Techork\PaymentService\Domain\Dispute\Exception\DisputeCannotChangeStatus;
use Techork\PaymentService\Domain\Dispute\Port\AcceptOutcome;
use Techork\PaymentService\Gateway\Command\DisputeConcessionCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Role\ConcedesDisputes;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\AcceptDisputeAdapter;
use Techork\PaymentService\Laravel\Port\DisputeCallRefused;
use Techork\PaymentService\Tests\Support\DisputePortCase;
use Techork\PaymentService\Tests\Support\DisputeReferenceTable;

/**
 * The concession: the one call in this domain that cannot be taken back, driven from the real
 * {@see AcceptDisputeAdapter} through a fake implementation of the role it depends on into F1's
 * aggregate.
 *
 * ## What "irreversible" is, as a testable claim
 *
 * The plan states the move — `needs_response → lost` — and that an operator's confirmation before
 * the call is the application's job (A2), which is why nothing in this file prompts and nothing in
 * the adapter holds a policy. What is left is the claim the *case* makes afterwards: once the
 * aggregate holds `ACCEPTED`, nothing moves it again. Three different deliveries try, and each of
 * them is a real one for a case we conceded:
 *
 * ```
 * won    the issuer crediting us after we gave up — not a replay, and the table refuses it
 * lost   the provider reporting our own concession back, which records nothing and stays ACCEPTED
 * ```
 *
 * The second is the subtle one: Stripe has no way to record *why* a case stopped, so a case we
 * conceded comes back as `lost`, the same word it would use if the issuer had decided against us.
 * Recording that as a fresh resolution would lose the fact that we chose to stop, which is what the
 * ledger reads as a recognised loss.
 *
 * ## No HTTP client
 *
 * Same as the submission side: the adapter holds a fake role, an in-memory reference table and a
 * gateway id. There is no driver in this file, so there is nothing here that could reach Stripe, and
 * rule 4 holds as a property of the composition.
 *
 * ## The case is named by us, and the provider's name for it is read from the table
 *
 * The close is addressed by the provider's own reference for the case, and the aggregate no longer
 * carries one: the request names our `DisputeId`, and the adapter reads the provider's name for the
 * case out of `gateway_references`. Every test here therefore hands it a table
 * ({@see DisputePortCase::references()}), and the assertions on `disputeReference` and on the
 * idempotency key are asserting the round trip through that row as well as the translation.
 */

/**
 * A fake {@see ConcedesDisputes}: every call it received, and either the reference a successful
 * close returns or the exception the driver raised in its place.
 */
final class DisputePortConcedesDisputes implements ConcedesDisputes
{
    /** @var list<DisputeConcessionCommand> every call this fake received, in order. */
    public array $commands = [];

    public function __construct(private readonly string|GatewayResult|Throwable $answer = DisputePortCase::REFERENCE) {}

    public function concede(DisputeConcessionCommand $command): GatewayResult
    {
        $this->commands[] = $command;

        if ($this->answer instanceof Throwable) {
            throw $this->answer;
        }

        return $this->answer instanceof GatewayResult
            ? $this->answer
            : GatewayResult::succeeded($this->answer);
    }
}

// ──────────────────────────────────────────────
//  the close, and what cannot follow it
// ──────────────────────────────────────────────

it('closes the case, records the concession, and leaves nothing able to move it again', function () {
    $case = DisputePortCase::open();
    $fake = new DisputePortConcedesDisputes;

    $outcome = new AcceptDisputeAdapter($fake, DisputePortCase::references(), GatewayId::generate())->accept(DisputePortCase::acceptance());
    DisputePortCase::answerAcceptance($case, $outcome, DisputePortCase::at('2026-09-14T12:00:00+00:00'));

    expect($outcome->wasAccepted())->toBeTrue()
        ->and($outcome->acceptedAmount())->toBeNull()
        ->and($case->status())->toBe(DisputeStatus::Accepted);

    // The provider has no way to say why a case stopped, so a case we conceded comes back as
    // `lost` — and that is our own decision arriving twice, not a new finding.
    $case->changeStatus(DisputePortCase::changeStatus($case->aggregateRootId(), DisputeStatus::Lost, 'evt_lost'));

    expect($case->status())->toBe(DisputeStatus::Accepted);

    // A win after we gave up is not a replay: the issuer does not decide for us on a case we
    // stopped fighting, and a status that flipped to WON would report money coming back that will
    // not.
    expect(fn () => $case->changeStatus(
        DisputePortCase::changeStatus($case->aggregateRootId(), DisputeStatus::Won, 'evt_won'),
    ))->toThrow(DisputeCannotChangeStatus::class, 'accepted');

    $accepted = array_values(array_filter(
        $case->releaseEvents(),
        static fn (object $event): bool => $event instanceof DisputeAccepted,
    ));

    expect($accepted)->toHaveCount(1)
        ->and($case->status())->toBe(DisputeStatus::Accepted);
});

/**
 * The whole case or none of it. Stripe's `close` takes no amount, so a partial amount is refused by
 * the driver before anything is sent — and it must travel there as the marked wiring error it is,
 * rather than being reported as the provider declining. An adapter that swallowed the amount and
 * closed anyway would give up the entire disputed sum on a call nobody can undo.
 */
it('passes a partial amount through to the provider rather than accepting in full', function () {
    $partial = new Money(5000, new Currency('USD'));
    $fake = new DisputePortConcedesDisputes(
        UnsupportedOperation::forGateway('stripe', 'concedePartially', 'Stripe closes the whole case'),
    );

    try {
        new AcceptDisputeAdapter($fake, DisputePortCase::references(), GatewayId::generate())->accept(DisputePortCase::acceptance($partial));
        $caught = null;
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(UnsupportedOperation::class)
        ->and($caught)->not->toBeInstanceOf(DisputeCallRefused::class)
        // It reached the driver as the amount the caller decided on, which is the only thing that
        // makes the refusal a refusal of *this* request.
        ->and($fake->commands[0]->partialAmount)->toBe($partial)
        ->and($fake->commands[0]->isPartial())->toBeTrue();
});

/**
 * A close the provider would not perform — a case it no longer considers open, a window that has
 * closed, an account not permitted to concede — is a throw and not an outcome. Reporting it as a
 * concession would book a loss nobody took, and reporting it as `alreadyClosed()` would claim the
 * provider resolved the case at a moment we did not witness.
 */
it('throws a typed refusal and concedes nothing when the provider will not close it', function () {
    $fake = new DisputePortConcedesDisputes(
        GatewayResult::failed('This dispute cannot be closed because it is already under review'),
    );

    try {
        new AcceptDisputeAdapter($fake, DisputePortCase::references(), GatewayId::generate())->accept(DisputePortCase::acceptance());
        $caught = null;
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(DisputeCallRefused::class)
        ->and($caught?->getMessage())->toContain(DisputePortCase::REFERENCE)
        ->and($caught?->getMessage())->toContain('already under review')
        // The case is not lost, and it is not known to be either — the message has to say so, or a
        // caller reads "we conceded" out of a call that did nothing.
        ->and($caught?->getMessage())->toContain('nothing was conceded');
});

/**
 * A case we hold no reference row for is our own bookkeeping failing, not the provider answering —
 * and on this call the distinction is at its sharpest, because the alternative reading is that we
 * gave up a case we never addressed.
 *
 * A missing row means we never recorded which case the provider knows, so nothing is sent and no
 * provider was asked anything: a `RuntimeException`, deliberately **not** {@see DisputeCallRefused},
 * which would report a refusal nobody made. `CaptureAdapter` records the same distinction for a
 * payment intent whose reference is missing, and this follows it.
 */
it('throws rather than closing anything when the case has no reference row', function () {
    $fake = new DisputePortConcedesDisputes;

    try {
        new AcceptDisputeAdapter($fake, new DisputeReferenceTable, GatewayId::generate())
            ->accept(DisputePortCase::acceptance());
        $caught = null;
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught)->not->toBeInstanceOf(DisputeCallRefused::class)
        ->and($caught?->getMessage())->toContain(DisputePortCase::DISPUTE_ID)
        // The close is the one call that cannot be taken back, so "nothing fell back to anything"
        // is not a nicety here: an adapter that guessed a reference would have conceded a case that
        // is not this one, irreversibly.
        ->and($fake->commands)->toBe([]);
});

/**
 * The key carries no digest, unlike the submission's, and that is the whole point: a repeated call
 * for the same case is the same intent, and a second close is the one thing this must not perform
 * twice.
 */
it('keys the close on the case alone so a retry is the same call', function () {
    $first = new DisputePortConcedesDisputes;
    $retry = new DisputePortConcedesDisputes;
    $gatewayId = GatewayId::generate();

    new AcceptDisputeAdapter($first, DisputePortCase::references(), $gatewayId)->accept(DisputePortCase::acceptance());
    new AcceptDisputeAdapter($retry, DisputePortCase::references(), $gatewayId)->accept(DisputePortCase::acceptance());

    expect($first->commands[0]->clientUniqueId)->toBe(DisputePortCase::REFERENCE.':close')
        ->and($retry->commands[0]->clientUniqueId)->toBe($first->commands[0]->clientUniqueId);
});

/**
 * What the role is handed: the provider's reference for the case, and the amount only when the
 * caller decided on one.
 */
it('hands the role the case reference and the amount the caller decided on', function () {
    $fake = new DisputePortConcedesDisputes;

    new AcceptDisputeAdapter($fake, DisputePortCase::references(), GatewayId::generate())->accept(DisputePortCase::acceptance());

    expect($fake->commands)->toHaveCount(1)
        ->and($fake->commands[0]->disputeReference)->toBe(DisputePortCase::REFERENCE)
        ->and($fake->commands[0]->partialAmount)->toBeNull()
        ->and($fake->commands[0]->isPartial())->toBeFalse();
});

/**
 * `alreadyClosed()` is a term of the outcome type that Stripe cannot produce, and it is not
 * invented here: a close on a case the provider no longer considers open comes back as an error
 * rather than as a case, so it arrives as the refusal above. The one thing that must not happen is
 * the aggregate recording a concession from it — {@see DisputePortCase::answerAcceptance()} records
 * nothing for that outcome, which is the fact this pins.
 */
it('records nothing from an outcome our call did not cause', function () {
    $case = DisputePortCase::open();

    DisputePortCase::answerAcceptance($case, AcceptOutcome::alreadyClosed(), DisputePortCase::at());

    $accepted = array_values(array_filter(
        $case->releaseEvents(),
        static fn (object $event): bool => $event instanceof DisputeAccepted,
    ));

    expect($case->status())->toBe(DisputeStatus::NeedsResponse)
        ->and($accepted)->toBe([]);
});
