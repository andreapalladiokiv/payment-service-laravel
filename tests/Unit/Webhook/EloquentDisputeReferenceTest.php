<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentTransactionIdResolver;

/**
 * The dispute half of the reference table: a provider's reference for a case, back to our id.
 *
 * A dispute resolution is the one dispute signal that is addressed by the provider's own reference
 * rather than by a PaymentIntent — it can arrive for a case whose payment never resolved, or after
 * a backlog import — so if this lookup cannot produce an answer, a resolution lands on nothing
 * exactly where it matters most. The row is written by whoever holds both the aggregate and the
 * provider's reference (A0); this test plays that writer, and pins the two halves together by
 * using the same constant the resolver reads.
 *
 * ## And the table is read in both directions now
 *
 * The aggregate no longer carries the provider's reference, which makes this row the *only* place
 * the two names meet: the resolution path above reads it by the provider's reference, and every
 * dispute port adapter reads it by our `DisputeId` before it can address a call. Both directions go
 * through {@see EloquentGatewayTransactionRepository} — `findForDispute()` / `saveForDispute()` —
 * and the pair is covered here on the real table for the same reason the resolver is: it is the
 * WHERE clause and the upsert that make the answers, and a fake would pin neither.
 *
 * A dispute row also carries the trap this file documents below: no morph map is registered, so
 * the row must never be asked for its `referenceable()` — the resolver queries the table by type
 * and reference, and that is what makes an unregistered type safe.
 *
 * Same harness as the sibling resolver tests: one shared in-memory SQLite Capsule, real classes,
 * nothing mocked. The table is created only when absent and emptied per test, because the Capsule
 * is shared across the database-backed files in this process.
 */
function bootDisputeReferenceSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors create_gateway_references_table + add_metadata_to_gateway_references; the
    // gateway_id foreign key is left off, as this exercises the lookup rather than the schema's
    // referential integrity.
    if (! Capsule::schema()->hasTable('gateway_references')) {
        Capsule::schema()->create('gateway_references', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('gateway_id');
            $table->string('referenceable_type');
            $table->uuid('referenceable_id');
            $table->string('reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['gateway_id', 'referenceable_type', 'referenceable_id']);
        });
    }

    Capsule::table('gateway_references')->delete();
}

/** What A0's recorder writes when it holds a case and the provider's reference for it. */
function recordDisputeReference(GatewayId $gatewayId, string $disputeId, string $reference): void
{
    Capsule::table('gateway_references')->insert([
        'id' => Uuid::uuid4()->toString(),
        'gateway_id' => $gatewayId->toString(),
        'referenceable_type' => EloquentGatewayTransactionRepository::TYPE_DISPUTE,
        'referenceable_id' => $disputeId,
        'reference' => $reference,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

beforeEach(function () {
    bootDisputeReferenceSchema();

    $this->resolver = new EloquentTransactionIdResolver;
    $this->transactions = new EloquentGatewayTransactionRepository;
    $this->gatewayId = GatewayId::generate();
    $this->disputeId = Uuid::uuid4()->toString();
});

// ──────────────────────────────────────────────
//  the write and the read a dispute port makes, on the real repository
// ──────────────────────────────────────────────

it('reads a dispute reference back by our id, and answers nothing for one it holds no row for', function () {
    // The direction a port adapter reads: the request names our `DisputeId` and the provider's own
    // reference for the case is what the call has to be addressed with. Written through the
    // repository rather than inserted, so the write and the read are the same row by construction —
    // the pair of halves the adapters depend on.
    $this->transactions->saveForDispute($this->gatewayId, $this->disputeId, 'dp_1QkDisputeCaseAlpha');

    expect($this->transactions->findForDispute($this->disputeId))->toBe('dp_1QkDisputeCaseAlpha')
        // The ordinary answer for a case we have never recorded, and the one the adapters turn into
        // a `RuntimeException` rather than into a call addressed at nothing.
        ->and($this->transactions->findForDispute(Uuid::uuid4()->toString()))->toBeNull();
});

it('keeps one row per case, so a later reference replaces the earlier one', function () {
    // Upsert-shaped, like the refund pair: writes overwrite on transition, and a dispute's reference
    // can move — ConnexPay's case is re-issued under a new `CaseNumber` inside one family. A second
    // row would leave the adapter reading whichever the query happened to return first, and the
    // retired reference resolving to nothing is the same contract the payment-intent side states.
    $this->transactions->saveForDispute($this->gatewayId, $this->disputeId, 'CB-2026-000123');
    $this->transactions->saveForDispute($this->gatewayId, $this->disputeId, 'CB-2026-000456');

    expect(Capsule::table('gateway_references')
        ->where('referenceable_type', EloquentGatewayTransactionRepository::TYPE_DISPUTE)
        ->where('referenceable_id', $this->disputeId)
        ->count())->toBe(1)
        ->and($this->transactions->findForDispute($this->disputeId))->toBe('CB-2026-000456')
        ->and($this->resolver->resolveDispute($this->gatewayId, 'CB-2026-000456'))->toBe($this->disputeId)
        ->and($this->resolver->resolveDispute($this->gatewayId, 'CB-2026-000123'))->toBeNull();
});

it('writes a dispute reference under the morph type the resolver and the adapters read', function () {
    // The two directions have to agree on the type string or nothing resolves at all, and the
    // resolver's own tests insert that string by hand. Here it is the repository's constant that
    // lands in the column, and the round trip through the resolver is what says the constant is the
    // one the row is looked up by.
    $this->transactions->saveForDispute($this->gatewayId, $this->disputeId, 'dp_1QkDisputeCaseBeta');

    expect(Capsule::table('gateway_references')
        ->where('referenceable_id', $this->disputeId)
        ->value('referenceable_type'))->toBe('dispute')
        ->and(Capsule::table('gateway_references')
            ->where('referenceable_id', $this->disputeId)
            ->value('referenceable_type'))->toBe(EloquentGatewayTransactionRepository::TYPE_DISPUTE)
        ->and($this->resolver->resolveDispute($this->gatewayId, 'dp_1QkDisputeCaseBeta'))->toBe($this->disputeId);
});

it('turns a stored dispute reference back into the aggregate id', function () {
    // Nuvei's references carry a `/` and a `+` — the value is opaque and is matched exactly, never
    // trimmed, case-folded or decoded, because any transformation made here becomes a lookup that
    // finds nothing.
    $providerReference = 'FRI3dJoEHQ/f8gaGUi86So4F8AQiznNgxWZFLvDJVxhBU3tq0kzakLH6wWpM+Llc';
    recordDisputeReference($this->gatewayId, $this->disputeId, $providerReference);

    $resolved = $this->resolver->resolveDispute($this->gatewayId, $providerReference);

    expect($resolved)->toBe($this->disputeId)
        // And the answer is one of our aggregate ids: a case id we minted, not the provider's.
        ->and(DisputeId::fromString((string) $resolved)->toString())->toBe($this->disputeId);
});

it('keeps disputes apart from payment intents and refunds that share a reference', function () {
    // Several providers number their objects from one sequence, and the same string can name a
    // sale, a return and a case. Only the morph type separates them, and resolving a dispute as a
    // payment intent — or the other way round — is a misattribution rather than a miss.
    $sharedReference = 'TXN-1000';
    $paymentIntentId = Uuid::uuid4()->toString();
    $refundId = Uuid::uuid4()->toString();
    $transactions = new EloquentGatewayTransactionRepository;

    $transactions->saveForPaymentIntent($this->gatewayId, $paymentIntentId, $sharedReference);
    $transactions->saveForRefund($this->gatewayId, $refundId, $sharedReference);
    recordDisputeReference($this->gatewayId, $this->disputeId, $sharedReference);

    expect($this->resolver->resolveDispute($this->gatewayId, $sharedReference))->toBe($this->disputeId)
        ->and($this->resolver->resolvePaymentIntent($this->gatewayId, $sharedReference))->toBe($paymentIntentId)
        ->and($this->resolver->resolveRefund($this->gatewayId, $sharedReference))->toBe($refundId);
});

it('does not resolve another gateway\'s dispute reference', function () {
    // A reference is unique inside one gateway account, so an unscoped lookup would let one
    // tenant's delivery resolve to another tenant's case.
    $otherGateway = GatewayId::generate();
    recordDisputeReference($this->gatewayId, $this->disputeId, 'CB-2026-000123');

    expect($this->resolver->resolveDispute($otherGateway, 'CB-2026-000123'))->toBeNull()
        ->and($this->resolver->resolveDispute($this->gatewayId, 'CB-2026-000123'))->toBe($this->disputeId);
});

it('answers with nothing for a dispute reference it holds no row for', function () {
    // The ordinary answer on a first delivery — the case is about to be observed for the first
    // time — and what `RecorderOutcome::NotFound` reads as "retry".
    expect($this->resolver->resolveDispute($this->gatewayId, 'never_seen'))->toBeNull();
});

it('reads a dispute row by type without a morph map, and writes the type as a plain string', function () {
    // The trap the row carries: no morph map entry means `referenceable()` would look for a class
    // named after the type and fail, so the resolver queries the table directly. Pinned here so
    // that a later "tidy-up" registering a morph alias, or a reader reaching for the relation,
    // shows up as a failing test rather than as a runtime error on a webhook.
    expect(Relation::getMorphedModel(EloquentGatewayTransactionRepository::TYPE_DISPUTE))->toBeNull();

    recordDisputeReference($this->gatewayId, $this->disputeId, 'CB-2026-000124');

    expect(Capsule::table('gateway_references')
        ->where('reference', 'CB-2026-000124')
        ->value('referenceable_type'))->toBe('dispute')
        ->and($this->resolver->resolveDispute($this->gatewayId, 'CB-2026-000124'))->toBe($this->disputeId);
});
