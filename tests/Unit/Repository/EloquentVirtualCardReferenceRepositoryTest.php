<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Repository\EloquentVirtualCardReferenceRepository;

/**
 * Virtual cards are the only tenant of `gateway_references` that is read in BOTH directions:
 * forward when we spend on a card we issued, and backward when a ConnexPay webhook arrives
 * carrying nothing but the gateway's `cardGuid`. That reverse lookup is what this file mostly
 * exists for, because it is the one query in the package that keys on `reference` — a column
 * with no unique constraint on it — and it is reached only from webhook ingestion, where a
 * wrong answer attributes a transaction to the wrong card.
 *
 * Pinned here: that the two directions agree, that re-issuing a reference for the same card
 * updates the single row and retires the old reference from the reverse lookup, that neither
 * direction crosses a gateway boundary or a morph type, and what the schema does and does not
 * enforce about the reference itself.
 *
 * Same harness as {@see EloquentGatewayTransactionRepositoryTest}: one global in-memory SQLite
 * Capsule, the real repository, nothing mocked, no new dev dependency. The table is created
 * only when absent and emptied per test, since several files in this process share it.
 */
function bootVirtualCardReferenceSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors create_gateway_references_table + add_metadata_to_gateway_references. The
    // gateway_id foreign key is left off; the composite unique key is kept, because it is the
    // conflict target `saveReference`'s upsert names and the subject of two tests below.
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

/**
 * Writes a row for another tenant of the same table directly, since the repositories that own
 * those morph types are not what these tests are about — only that their rows stay invisible.
 */
function virtualCardForeignReferenceRow(GatewayId $gatewayId, string $morphType, string $reference): string
{
    $referenceableId = Uuid::uuid4()->toString();

    Capsule::table('gateway_references')->insert([
        'id' => Uuid::uuid4()->toString(),
        'gateway_id' => $gatewayId->toString(),
        'referenceable_type' => $morphType,
        'referenceable_id' => $referenceableId,
        'reference' => $reference,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    return $referenceableId;
}

beforeEach(function () {
    bootVirtualCardReferenceSchema();

    $this->repo = new EloquentVirtualCardReferenceRepository;
    $this->gatewayId = GatewayId::generate();
    $this->cardId = Uuid::uuid4()->toString();
});

it('answers both directions for a reference it stored', function () {
    $this->repo->saveReference($this->gatewayId, $this->cardId, 'card-guid-1');

    expect($this->repo->find($this->gatewayId, $this->cardId))->toBe('card-guid-1')
        ->and($this->repo->findVirtualCardId($this->gatewayId, 'card-guid-1'))->toBe($this->cardId);
});

it('answers with nothing for a card and a reference it has never seen', function () {
    // The reverse lookup runs on webhook delivery, where an unknown reference is routine: it
    // may belong to a card issued outside this system. Null is the answer, not an exception.
    expect($this->repo->find($this->gatewayId, $this->cardId))->toBeNull()
        ->and($this->repo->findVirtualCardId($this->gatewayId, 'card-guid-unknown'))->toBeNull();
});

it('replaces the reference on the one row when a card is re-issued', function () {
    // A reissued card keeps our id and gets a new gateway-side guid. The upsert must move the
    // reference on the existing row: a second row would leave the forward lookup choosing
    // between two references for one card.
    $this->repo->saveReference($this->gatewayId, $this->cardId, 'card-guid-1');
    $this->repo->saveReference($this->gatewayId, $this->cardId, 'card-guid-2');

    expect($this->repo->find($this->gatewayId, $this->cardId))->toBe('card-guid-2')
        ->and(Capsule::table('gateway_references')->count())->toBe(1)
        // And the retired guid stops resolving, so a late webhook quoting it is treated as
        // unknown rather than silently attributed to the card's current incarnation.
        ->and($this->repo->findVirtualCardId($this->gatewayId, 'card-guid-1'))->toBeNull();
});

it('keeps each gateway\'s virtual cards to itself in both directions', function () {
    // Gateway-side guids are namespaced by the provider that issued them, so an unscoped
    // reverse lookup would let one tenant's webhook resolve to another tenant's card.
    $otherGateway = GatewayId::generate();
    $this->repo->saveReference($this->gatewayId, $this->cardId, 'card-guid-1');

    expect($this->repo->find($otherGateway, $this->cardId))->toBeNull()
        ->and($this->repo->findVirtualCardId($otherGateway, 'card-guid-1'))->toBeNull()
        ->and($this->repo->findVirtualCardId($this->gatewayId, 'card-guid-1'))->toBe($this->cardId);
});

it('does not resolve a payment intent\'s reference as a virtual card', function () {
    // `gateway_references` is shared with payment intents, refunds and instruments, and
    // nothing stops two providers' identifiers from colliding as strings. Only the morph type
    // separates them, and this direction is the dangerous one: resolving a sale reference to a
    // card id would attach a spend event to a card that never made it.
    $sharedString = 'REF-COLLIDES';
    virtualCardForeignReferenceRow($this->gatewayId, 'payment_intent', $sharedString);
    virtualCardForeignReferenceRow($this->gatewayId, 'token', $sharedString);

    expect($this->repo->findVirtualCardId($this->gatewayId, $sharedString))->toBeNull();

    // The card's own row, added last, is the only one it may find.
    $this->repo->saveReference($this->gatewayId, $this->cardId, $sharedString);

    expect($this->repo->findVirtualCardId($this->gatewayId, $sharedString))->toBe($this->cardId);
});

it('does not report a virtual card\'s id when the card id is stored under another morph type', function () {
    // The forward direction of the same separation: a token whose row happens to carry this
    // uuid must not hand its reference to the virtual-card lookup.
    Capsule::table('gateway_references')->insert([
        'id' => Uuid::uuid4()->toString(),
        'gateway_id' => $this->gatewayId->toString(),
        'referenceable_type' => 'token',
        'referenceable_id' => $this->cardId,
        'reference' => 'tok_ref',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    expect($this->repo->find($this->gatewayId, $this->cardId))->toBeNull();
});

it('lets two cards hold the same gateway reference, because the schema keys the card', function () {
    // Documents the shape of the uniqueness, which is easy to assume the other way round: the
    // unique key is (gateway, type, referenceable_id), so nothing prevents a second card from
    // claiming a reference another card already holds. That makes the reverse lookup ambiguous
    // by construction — it answers with one arbitrary row — which is a fact about the reverse
    // direction worth knowing at the point a caller decides to trust it.
    $otherCardId = Uuid::uuid4()->toString();

    $this->repo->saveReference($this->gatewayId, $this->cardId, 'card-guid-1');
    $this->repo->saveReference($this->gatewayId, $otherCardId, 'card-guid-1');

    expect(Capsule::table('gateway_references')->where('reference', 'card-guid-1')->count())->toBe(2)
        ->and($this->repo->find($this->gatewayId, $this->cardId))->toBe('card-guid-1')
        ->and($this->repo->find($this->gatewayId, $otherCardId))->toBe('card-guid-1')
        ->and($this->repo->findVirtualCardId($this->gatewayId, 'card-guid-1'))
        ->toBeIn([$this->cardId, $otherCardId]);
});

it('clears a failure recorded against the card when a reference finally lands', function () {
    // Virtual-card rows share the table with the instrument repository's failures, so a row
    // may already exist carrying a decline reason and no reference. `saveReference` lists
    // failure_reason among the columns it updates and writes null there, which is what stops a
    // recovered card from reporting a failure it no longer has.
    Capsule::table('gateway_references')->insert([
        'id' => Uuid::uuid4()->toString(),
        'gateway_id' => $this->gatewayId->toString(),
        'referenceable_type' => 'virtual_card',
        'referenceable_id' => $this->cardId,
        'reference' => null,
        'failure_reason' => 'issuing declined',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $this->repo->saveReference($this->gatewayId, $this->cardId, 'card-guid-1');

    expect(Capsule::table('gateway_references')->count())->toBe(1)
        ->and(Capsule::table('gateway_references')->where('referenceable_id', $this->cardId)->value('failure_reason'))->toBeNull()
        ->and($this->repo->find($this->gatewayId, $this->cardId))->toBe('card-guid-1');
});
