<?php

declare(strict_types=1);

use EventSauce\EventSourcing\Snapshotting\Snapshot;
use Illuminate\Database\ConnectionInterface;
use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateSnapshotRepository;
use Techork\PaymentService\Tests\Support\EventStreamDatabase;

/**
 * The snapshot side of the stream: one row per aggregate, upserted.
 *
 * A snapshot is a cache, but not a harmless one. It is applied *instead of* the events it
 * covers, so a snapshot that reads back wrong does not slow a rehydration down — it returns
 * an aggregate whose state never existed, and the events that would have corrected it are
 * exactly the ones skipped. The two things worth holding still are therefore that a snapshot
 * round-trips whole, and that a missing one answers `null` rather than anything that could be
 * mistaken for an empty snapshot: `null` is what sends the snapshotting repository back to
 * the full stream, which is always safe.
 */
function snapshotRepoConnection(): ConnectionInterface
{
    return EventStreamDatabase::connection();
}

/**
 * A real `CheckoutAggregate` snapshot state, nulls and nested arrays included — the shape
 * `createSnapshotState()` produces, so the round-trip is over what actually gets stored.
 *
 * @return array<string, mixed>
 */
function snapshotRepoCheckoutState(string $status = 'pending'): array
{
    return [
        'status' => $status,
        'amount' => '5000',
        'currency' => 'USD',
        'description' => 'A checkout worth snapshotting',
        'callback_url' => null,
        'expires_at' => null,
        'metadata' => ['order_ref' => 'ORD-1', 'nested' => ['deep' => true]],
        'plan' => null,
    ];
}

it('returns a persisted snapshot with its id, version and state intact', function () {
    $connection = snapshotRepoConnection();
    $repository = new IlluminateSnapshotRepository($connection);

    $id = CheckoutId::generate();
    $repository->persist(new Snapshot($id, 7, snapshotRepoCheckoutState()));

    $read = $repository->retrieve($id);

    expect($read)->toBeInstanceOf(Snapshot::class)
        ->and($read?->aggregateRootId()->toString())->toBe($id->toString())
        // The version is load-bearing twice over: it is what the snapshotting repository
        // asks the message repository for events *after*, and what it reports as the
        // aggregate's version when no later events exist.
        ->and($read?->aggregateRootVersion())->toBe(7)
        // Associative decoding, all the way down. A snapshot decoded to stdClass would
        // reach `reconstituteFromSnapshotState()`, which indexes it as an array, and fail
        // on every aggregate.
        ->and($read?->state())->toBe(snapshotRepoCheckoutState());
});

it('answers null for an aggregate that has no snapshot', function () {
    $connection = snapshotRepoConnection();
    $repository = new IlluminateSnapshotRepository($connection);

    // Another aggregate's snapshot is present, so this is selection rather than an empty table.
    $repository->persist(new Snapshot(CheckoutId::generate(), 3, snapshotRepoCheckoutState()));

    // Null, not a zero-version snapshot: null is what makes the snapshotting repository
    // replay the full stream, and a zero-version snapshot with empty state would instead
    // hand back a blank aggregate as though that were its history.
    expect($repository->retrieve(CheckoutId::generate()))->toBeNull();
});

it('keeps one snapshot per aggregate, replacing the older state and version', function () {
    $connection = snapshotRepoConnection();
    $repository = new IlluminateSnapshotRepository($connection);
    $id = CheckoutId::generate();

    $repository->persist(new Snapshot($id, 3, snapshotRepoCheckoutState('pending')));
    $repository->persist(new Snapshot($id, 9, snapshotRepoCheckoutState('cancelled')));

    $read = $repository->retrieve($id);

    // Upsert, not insert. A second row for the same aggregate would make `first()` a coin
    // toss between a current snapshot and a stale one — and the stale one wins whenever the
    // storage engine feels like returning it first.
    expect($connection->table('aggregate_snapshots')->count())->toBe(1)
        ->and($read?->aggregateRootVersion())->toBe(9)
        ->and($read?->state()['status'])->toBe('cancelled');
});

it('keeps snapshots of different aggregates apart', function () {
    $connection = snapshotRepoConnection();
    $repository = new IlluminateSnapshotRepository($connection);

    $checkout = CheckoutId::generate();
    $intent = PaymentIntentId::generate();

    $repository->persist(new Snapshot($checkout, 1, snapshotRepoCheckoutState('pending')));
    $repository->persist(new Snapshot($intent, 2, ['status' => 'charged']));

    // The table is keyed by the id string alone, with no aggregate-type column, so this is
    // the assertion that two aggregate families sharing it cannot collide.
    expect($repository->retrieve($checkout)?->state())->toBe(snapshotRepoCheckoutState('pending'))
        ->and($repository->retrieve($intent)?->state())->toBe(['status' => 'charged'])
        ->and($connection->table('aggregate_snapshots')->count())->toBe(2);
});

it('stamps the snapshot with the time it was written', function () {
    $connection = snapshotRepoConnection();
    $repository = new IlluminateSnapshotRepository($connection);
    $id = CheckoutId::generate();

    $repository->persist(new Snapshot($id, 1, snapshotRepoCheckoutState()));

    // `created_at` is the only signal that a snapshot is stale, and since the row is
    // upserted rather than appended it is also the only record of when the current state was
    // computed. It is not read back into the Snapshot, so nothing but this notices if the
    // column stops being written.
    expect($connection->table('aggregate_snapshots')->value('created_at'))->toBeString();
});

it('clamps a negative stored version to zero rather than breaking the snapshot contract', function () {
    $connection = snapshotRepoConnection();
    $repository = new IlluminateSnapshotRepository($connection);
    $id = CheckoutId::generate();

    // Written past the repository, the way a bad migration or a hand-repaired row would be.
    // `Snapshot` declares its version `positive-int|0`, and the column is only as trustworthy
    // as whatever last touched it, so the read path is where that has to hold.
    $connection->table('aggregate_snapshots')->insert([
        'aggregate_root_id' => $id->toString(),
        'aggregate_root_version' => -1,
        'state' => json_encode(snapshotRepoCheckoutState()),
        'created_at' => '2026-01-01 00:00:00',
    ]);

    // Zero means "replay everything after version 0", i.e. the whole stream — the safe
    // reading of a version that cannot be trusted.
    expect($repository->retrieve($id)?->aggregateRootVersion())->toBe(0);
});

it('honours a table name other than the default', function () {
    $connection = snapshotRepoConnection();
    $connection->statement('CREATE TABLE tenant_snapshots (aggregate_root_id VARCHAR PRIMARY KEY, aggregate_root_version INTEGER, state TEXT, created_at DATETIME)');

    $repository = new IlluminateSnapshotRepository($connection, 'tenant_snapshots');
    $id = CheckoutId::generate();

    $repository->persist(new Snapshot($id, 4, snapshotRepoCheckoutState()));

    // The table is a constructor default, not a hardcoded string: an app that partitions its
    // snapshots has to be able to say so, and both halves must agree on the answer.
    expect($repository->retrieve($id)?->aggregateRootVersion())->toBe(4)
        ->and($connection->table('aggregate_snapshots')->count())->toBe(0);
});
