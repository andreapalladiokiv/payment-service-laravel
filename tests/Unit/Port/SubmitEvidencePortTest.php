<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\Event\EvidenceAttached;
use Techork\PaymentService\Domain\Dispute\Event\EvidenceSubmitted;
use Techork\PaymentService\Domain\Dispute\SubmissionState;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceFormat;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceItem;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidencePackage;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceRequirements;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Gateway\Command\DisputeEvidenceCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Role\SubmitsDisputeEvidence;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\DisputeCallRefused;
use Techork\PaymentService\Laravel\Port\SubmitEvidenceAdapter;
use Techork\PaymentService\Tests\Support\DisputePortCase;
use Techork\PaymentService\Tests\Support\DisputeReferenceTable;

/**
 * F7's two-step flow, end to end on our side of the wire: the real {@see SubmitEvidenceAdapter}
 * against a fake implementation of the role it depends on, and the outcome it returns recorded into
 * F1's aggregate.
 *
 * ## What is being proven, and why a return value is not enough
 *
 * The claim under test is a claim about the *case*: evidence staged with `submit: false` leaves the
 * aggregate in `DRAFT`, and the case stays out of `UNDER_REVIEW` — the provider has been told
 * nothing it can act on, so a case reported as under review would be reporting a response that has
 * not been filed. A test that asserted `SubmissionOutcome::$state` alone would pin the adapter's
 * word for it and nothing about the aggregate that has to accept that word, so each test here
 * drives the outcome into a real aggregate and asserts what the case recorded.
 * {@see DisputePortCase::answerSubmission()} is the application's step (A2's), written out in the
 * suite's fixtures rather than buried here, and the three files of this seam share it.
 *
 * ## No HTTP client, anywhere
 *
 * The adapter is constructed with a fake role, an in-memory reference table and a gateway id, and
 * there is no driver in this file to reach a provider with. That is rule 4 held as a property of the
 * composition rather than of a mock: the class under test cannot make a call, because nothing it
 * holds can. The provider-side request bodies are pinned in the Stripe package against its own
 * recorded payloads; what is pinned here is the translation across the seam.
 *
 * ## The case is named by us, and the provider's name for it is read from the table
 *
 * A submission is addressed by the provider's own reference for the case, and that reference is no
 * longer anywhere near the aggregate: the request carries our `DisputeId`, and the adapter reads the
 * provider's name for the case out of `gateway_references` before it can compose a call. So every
 * test here hands it a table ({@see DisputePortCase::references()}) and the row in it is the
 * fixture's, not the adapter's — the assertions below on `disputeReference` are therefore asserting
 * the round trip as well as the translation.
 */

/**
 * A fake {@see SubmitsDisputeEvidence} — the role the adapter talks to, standing where a driver
 * would.
 *
 * Hand-written rather than mocked so that every call it received is readable as data: `$commands`
 * is asserted on directly, and a call nobody asked for still shows up. The answer is one of the
 * three shapes a driver between it and the provider can produce: the reference a successful call
 * returns, the result a call the provider refused returns, or the exception a rule violation raises
 * before anything is sent.
 */
final class DisputePortSubmitsDisputeEvidence implements SubmitsDisputeEvidence
{
    /** @var list<DisputeEvidenceCommand> every call this fake received, in order. */
    public array $commands = [];

    public function __construct(private readonly string|GatewayResult|Throwable $answer = DisputePortCase::REFERENCE) {}

    public function submitEvidence(DisputeEvidenceCommand $command): GatewayResult
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
//  staging, and what the case records
// ──────────────────────────────────────────────

it('stages the evidence and leaves the aggregate in DRAFT, still waiting for us', function () {
    $case = DisputePortCase::open();
    $package = DisputePortCase::package();
    $fake = new DisputePortSubmitsDisputeEvidence;

    $outcome = new SubmitEvidenceAdapter($fake, DisputePortCase::references(), GatewayId::generate())
        ->submit(DisputePortCase::submission($package, stageOnly: true));

    DisputePortCase::answerSubmission($case, $package, $outcome, DisputePortCase::at());

    expect($outcome->state)->toBe(SubmissionState::Draft)
        ->and($case->submissionState())->toBe(SubmissionState::Draft)
        // The two halves of the F7 clause: the case is in DRAFT, and the provider's own status has
        // not moved — staging is invisible to the issuer, and UNDER_REVIEW would report a response
        // that has not been filed.
        ->and($case->status())->toBe(DisputeStatus::NeedsResponse)
        ->and($case->status())->not->toBe(DisputeStatus::UnderReview)
        ->and($case->stagedEvidence())->toBe(DisputePortCase::types($package))
        ->and($case->submittedEvidence())->toBe([]);

    $attached = array_values(array_filter(
        $case->releaseEvents(),
        static fn (object $event): bool => $event instanceof EvidenceAttached,
    ));

    expect($attached)->toHaveCount(1)
        ->and($attached[0]->from)->toBe(SubmissionState::None)
        ->and($attached[0]->to)->toBe(SubmissionState::Draft)
        ->and($attached[0]->types)->toBe(DisputePortCase::types($package))
        // Ours, so no delivery key: a repeat of our own staging is caught by the state, not by a
        // provider's event.
        ->and($attached[0]->signalKey)->toBeNull();
});

/**
 * The other half of the dual control: the second call files what the first one staged. The same
 * adapter makes it, and only the flag says which call it is.
 */
it('files the same package on the second call and moves the case to SUBMITTED', function () {
    $case = DisputePortCase::open();
    $package = DisputePortCase::package();
    $staging = new DisputePortSubmitsDisputeEvidence;
    $sent = new DisputePortSubmitsDisputeEvidence;
    $gatewayId = GatewayId::generate();

    $staged = new SubmitEvidenceAdapter($staging, DisputePortCase::references(), $gatewayId)
        ->submit(DisputePortCase::submission($package, stageOnly: true));
    DisputePortCase::answerSubmission($case, $package, $staged, DisputePortCase::at());

    $filed = new SubmitEvidenceAdapter($sent, DisputePortCase::references(), $gatewayId)
        ->submit(DisputePortCase::submission($package, stageOnly: false));
    DisputePortCase::answerSubmission($case, $package, $filed, DisputePortCase::at('2026-09-14T11:00:00+00:00'));

    expect($filed->state)->toBe(SubmissionState::Submitted)
        ->and($case->submissionState())->toBe(SubmissionState::Submitted)
        ->and($case->submittedEvidence())->toBe(DisputePortCase::types($package))
        // Filing is our statement that the response went out; the provider's status is still its
        // own, and it moves when Stripe processes the response rather than when we send it.
        ->and($case->status())->toBe(DisputeStatus::NeedsResponse)
        ->and($sent->commands[0]->submit)->toBeTrue();
});

/**
 * What crosses the role boundary, asserted on the command the fake received: our facts arrive as
 * the domain's own vocabulary plus a wire media type, and the reference is the provider's.
 */
it('hands the role the case reference, the facts, and a media type only for documents', function () {
    $fake = new DisputePortSubmitsDisputeEvidence;

    new SubmitEvidenceAdapter($fake, DisputePortCase::references(), GatewayId::generate())
        ->submit(DisputePortCase::submission(stageOnly: true));

    $command = $fake->commands[0];

    expect($fake->commands)->toHaveCount(1)
        ->and($command->disputeReference)->toBe(DisputePortCase::REFERENCE)
        ->and($command->submit)->toBeFalse()
        ->and($command->isEmpty())->toBeFalse()
        ->and($command->types())->toBe(['statement_descriptor', 'proof_of_delivery_or_service'])
        ->and($command->evidence()[0]->mediaType)->toBeNull()
        ->and($command->evidence()[1]->mediaType)->toBe('application/pdf');
});

// ──────────────────────────────────────────────
//  the idempotency key, which is what keeps the two steps apart
// ──────────────────────────────────────────────

/**
 * Staging and filing are two calls to the same endpoint, and Stripe's idempotency cache lasts 24
 * hours: keyed on the case alone, the second call would be answered with the first one's response
 * and the response would never go out.
 */
it('keys the staging and the filing of one package differently', function () {
    $stage = new DisputePortSubmitsDisputeEvidence;
    $send = new DisputePortSubmitsDisputeEvidence;
    $gatewayId = GatewayId::generate();

    new SubmitEvidenceAdapter($stage, DisputePortCase::references(), $gatewayId)->submit(DisputePortCase::submission(stageOnly: true));
    new SubmitEvidenceAdapter($send, DisputePortCase::references(), $gatewayId)->submit(DisputePortCase::submission(stageOnly: false));

    expect($stage->commands[0]->clientUniqueId)->toStartWith(DisputePortCase::REFERENCE.':stage:')
        ->and($send->commands[0]->clientUniqueId)->toStartWith(DisputePortCase::REFERENCE.':submit:')
        ->and($stage->commands[0]->clientUniqueId)->not->toBe($send->commands[0]->clientUniqueId);
});

/**
 * A package corrected and re-staged the same day must reach Stripe, and an identical one must not
 * be staged twice. Both follow from the evidence being in the key.
 */
it('replays an identical staging from the key and sends a corrected one under a new key', function () {
    $first = new DisputePortSubmitsDisputeEvidence;
    $again = new DisputePortSubmitsDisputeEvidence;
    $corrected = new DisputePortSubmitsDisputeEvidence;
    $gatewayId = GatewayId::generate();

    new SubmitEvidenceAdapter($first, DisputePortCase::references(), $gatewayId)->submit(DisputePortCase::submission(stageOnly: true));
    new SubmitEvidenceAdapter($again, DisputePortCase::references(), $gatewayId)->submit(DisputePortCase::submission(stageOnly: true));

    $package = new EvidencePackage(DisputePortCase::package()->cardBrand, '10.4', [
        new EvidenceItem(EvidenceType::StatementDescriptor, 'ACME*SUBSCRIPTION (corrected)'),
        new EvidenceItem(
            EvidenceType::ProofOfDeliveryOrService,
            base64_encode('%PDF-1.4 the signed delivery note'),
            EvidenceFormat::Pdf,
        ),
    ]);

    new SubmitEvidenceAdapter($corrected, DisputePortCase::references(), $gatewayId)->submit(DisputePortCase::submission($package, stageOnly: true));

    expect($again->commands[0]->clientUniqueId)->toBe($first->commands[0]->clientUniqueId)
        ->and($corrected->commands[0]->clientUniqueId)->not->toBe($first->commands[0]->clientUniqueId);
});

/**
 * The order of the package is part of the request, not noise: the provider composes several of our
 * facts into one text field in the order it receives them, so two packages that differ only in
 * order are two different submissions.
 */
it('keys two packages that differ only in order differently', function () {
    $first = new DisputePortSubmitsDisputeEvidence;
    $reordered = new DisputePortSubmitsDisputeEvidence;
    $gatewayId = GatewayId::generate();
    $package = DisputePortCase::package();

    new SubmitEvidenceAdapter($first, DisputePortCase::references(), $gatewayId)->submit(DisputePortCase::submission($package, stageOnly: true));
    new SubmitEvidenceAdapter($reordered, DisputePortCase::references(), $gatewayId)->submit(DisputePortCase::submission(
        new EvidencePackage($package->cardBrand, $package->reasonCode, array_reverse($package->items())),
        stageOnly: true,
    ));

    expect($reordered->commands[0]->clientUniqueId)->not->toBe($first->commands[0]->clientUniqueId);
});

// ──────────────────────────────────────────────
//  refusals
// ──────────────────────────────────────────────

/**
 * A provider that would not take the evidence. The case must not move: nothing was filed, and the
 * aggregate is left exactly where it was, which is why this is a throw rather than a failed outcome
 * the caller could apply.
 */
it('throws a typed refusal and leaves the case exactly where it was', function () {
    $case = DisputePortCase::open();
    $fake = new DisputePortSubmitsDisputeEvidence(
        GatewayResult::failed('Evidence is not permitted for this dispute'),
    );

    try {
        new SubmitEvidenceAdapter($fake, DisputePortCase::references(), GatewayId::generate())->submit(DisputePortCase::submission());
        $caught = null;
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(DisputeCallRefused::class)
        ->and($caught?->getMessage())->toContain(DisputePortCase::REFERENCE)
        ->and($caught?->getMessage())->toContain('Evidence is not permitted for this dispute')
        // A dispute the network is asking about is not a payment the issuer declined, and the
        // refusal vocabulary of the payment intent is not borrowed for it: an aggregate owns its
        // ports, so this must not arrive as a decline a payment-intent caller would catch.
        ->and($caught)->not->toBeInstanceOf(GatewayDeclinedException::class)
        ->and($case->submissionState())->toBe(SubmissionState::None)
        ->and($case->status())->toBe(DisputeStatus::NeedsResponse)
        ->and($case->stagedEvidence())->toBe([]);
});

/**
 * A case we hold no reference row for is our own bookkeeping failing, not the provider answering.
 *
 * The call is addressed by the reference the adapter reads out of `gateway_references`, so a missing
 * row means we never recorded which case the provider knows: there is nothing to file, nothing is
 * sent, and no provider was asked anything. It must reach the caller as a `RuntimeException` and
 * deliberately **not** as {@see DisputeCallRefused} — the provider refused nothing, and dressing our
 * own gap as a refusal sends an operator looking for a case the network rejected. `CaptureAdapter`
 * records the same distinction for a payment intent whose reference is missing, and this follows it.
 */
it('throws rather than calling anyone when the case has no reference row', function () {
    $fake = new DisputePortSubmitsDisputeEvidence;

    try {
        new SubmitEvidenceAdapter($fake, new DisputeReferenceTable, GatewayId::generate())
            ->submit(DisputePortCase::submission());
        $caught = null;
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught)->not->toBeInstanceOf(DisputeCallRefused::class)
        ->and($caught?->getMessage())->toContain(DisputePortCase::DISPUTE_ID)
        // Nothing fell back to anything: the fake was never reached, which is the whole claim. An
        // adapter that guessed a reference would address a case that is not this one.
        ->and($fake->commands)->toBe([]);
});

/**
 * A provider-side rule — Stripe's 150,000-character ceiling, raised where the evidence block is
 * composed — is not a provider that said no. Nothing was sent and nothing was uploaded, so it must
 * reach the caller as itself rather than dressed as a refusal by the provider, which would send an
 * operator looking for a case the network rejected.
 *
 * The exception is raised by the fake and compared by identity, because what is under test is that
 * the port neither swallows nor re-types it. The ceiling itself, and the type Stripe raises for it,
 * are pinned in the Stripe package against the provider's own rule; naming that type here would
 * couple this package to Stripe, which the package hierarchy does not permit.
 */
it('lets a provider-side rule refusal propagate unchanged', function () {
    $rule = new InvalidArgumentException(
        'The evidence for dispute '.DisputePortCase::REFERENCE.' is 150001 characters of text, '
        .'which is over Stripe\'s limit of 150000.',
    );

    try {
        new SubmitEvidenceAdapter(new DisputePortSubmitsDisputeEvidence($rule), DisputePortCase::references(), GatewayId::generate())
            ->submit(DisputePortCase::submission());
        $caught = null;
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBe($rule);
});

/**
 * An empty package is expressible and this does not add a second completeness rule beside the one
 * that exists: {@see EvidenceRequirements::missingFrom()} answers what a reason code still needs,
 * and it is asked by the application that assembled the package. An adapter that refused here would
 * be refusing a submission the network takes, on a case whose window is running.
 */
it('sends an empty package rather than inventing its own completeness rule', function () {
    $fake = new DisputePortSubmitsDisputeEvidence;

    $outcome = new SubmitEvidenceAdapter($fake, DisputePortCase::references(), GatewayId::generate())->submit(DisputePortCase::submission(
        new EvidencePackage(DisputePortCase::package()->cardBrand, '10.4'),
    ));

    expect($outcome->state)->toBe(SubmissionState::Draft)
        ->and($fake->commands[0]->isEmpty())->toBeTrue()
        ->and($fake->commands[0]->evidence())->toBe([]);
});

/**
 * Staging is a move on one axis and only one. The window the case is being answered in, the
 * provider's own cycle and the status all belong to somebody else, and a submission that nudged
 * any of them would be reporting a fact nobody stated.
 */
it('stages without touching the deadline or the provider cycle', function () {
    $case = DisputePortCase::open();
    $package = DisputePortCase::package();

    DisputePortCase::answerSubmission(
        $case,
        $package,
        new SubmitEvidenceAdapter(new DisputePortSubmitsDisputeEvidence, DisputePortCase::references(), GatewayId::generate())
            ->submit(DisputePortCase::submission($package, stageOnly: true)),
        DisputePortCase::at(),
    );

    expect($case->deadlineAt()?->getTimestamp())
        ->toBe(DisputePortCase::at('2026-09-28T00:00:00+00:00')->getTimestamp())
        ->and($case->status())->toBe(DisputeStatus::NeedsResponse);
});

it('releases an EvidenceSubmitted event when the response is filed', function () {
    $case = DisputePortCase::open();
    $package = DisputePortCase::package();

    DisputePortCase::answerSubmission(
        $case,
        $package,
        new SubmitEvidenceAdapter(new DisputePortSubmitsDisputeEvidence, DisputePortCase::references(), GatewayId::generate())
            ->submit(DisputePortCase::submission($package, stageOnly: false)),
        DisputePortCase::at(),
    );

    $submitted = array_values(array_filter(
        $case->releaseEvents(),
        static fn (object $event): bool => $event instanceof EvidenceSubmitted,
    ));

    expect($submitted)->toHaveCount(1)
        ->and($submitted[0]->from)->toBe(SubmissionState::None)
        ->and($submitted[0]->to)->toBe(SubmissionState::Submitted)
        ->and($submitted[0]->types)->toBe(DisputePortCase::types($package));
});
