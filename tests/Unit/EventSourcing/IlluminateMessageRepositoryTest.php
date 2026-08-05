<?php

declare(strict_types=1);

use EventSauce\EventSourcing\Header;
use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\OffsetCursor;
use EventSauce\EventSourcing\UnableToPersistMessages;
use EventSauce\EventSourcing\UnableToRetrieveMessages;
use Illuminate\Database\ConnectionInterface;
use Money\Currency;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCancelled;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCreated;
use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateMessageRepository;
use Techork\PaymentService\Tests\Support\EventStreamDatabase;

/**
 * The write and read halves of the event stream itself.
 *
 * This class was inlined from `eventsauce/message-repository-for-illuminate` to drop that
 * package's Laravel version ceiling, which means the upstream test suite no longer covers it
 * and every behaviour it inherited is now ours to keep. It matters more than its size
 * suggests: a defect here does not fail a request, it produces history that cannot be read
 * back, and an aggregate that cannot be rehydrated is a payment nobody can capture, refund
 * or explain.
 *
 * What these tests pin is therefore the contract EventSauce's repositories depend on, not the
 * SQL: a message returns as the same event with the same headers, the streams stay separated,
 * the order is by version, and the generator's *return* value — which is how the aggregate
 * repository learns what version it just loaded — is the last message's version.
 */
function messageRepoConnection(): ConnectionInterface
{
    return EventStreamDatabase::connection();
}

function messageRepoFor(ConnectionInterface $connection, string $table = 'stored_events'): IlluminateMessageRepository
{
    return new IlluminateMessageRepository($connection, $table, EventStreamDatabase::messageSerializer());
}

function messageRepoCheckoutCreated(int $amount = 5000): CheckoutCreated
{
    return new CheckoutCreated(
        new Money($amount, new Currency('USD')),
        'A checkout worth reading back',
        'https://merchant.example/return',
        null,
        ['order_ref' => 'ORD-1'],
    );
}

/**
 * Drains a message generator and hands back both halves of its result, because the return
 * value is only available once the generator has run to completion and half the contract
 * lives there.
 *
 * @return array{0: list<Message>, 1: mixed}
 */
function messageRepoDrain(Generator $messages): array
{
    $drained = [];

    foreach ($messages as $message) {
        $drained[] = $message;
    }

    return [$drained, $messages->getReturn()];
}

it('returns a persisted message as the same event, headers and version', function () {
    $connection = messageRepoConnection();
    $repository = messageRepoFor($connection);

    $id = CheckoutId::generate();
    $event = messageRepoCheckoutCreated();

    $repository->persist(new Message($event, [
        Header::AGGREGATE_ROOT_ID => $id,
        Header::AGGREGATE_ROOT_VERSION => 1,
        Header::AGGREGATE_ROOT_TYPE => 'checkout',
    ]));

    [$messages] = messageRepoDrain($repository->retrieveAll($id));

    expect($messages)->toHaveCount(1);

    $read = $messages[0];

    // Equality, not identity: the event is rebuilt from the stored payload, so this is the
    // assertion that the whole graph — Money and its Currency included — survived the trip.
    expect($read->payload())->toEqual($event)
        ->and($read->header(Header::AGGREGATE_ROOT_VERSION))->toBe(1)
        ->and($read->header(Header::AGGREGATE_ROOT_TYPE))->toBe('checkout')
        // The id comes back as the aggregate's own id class, not the string that was stored,
        // because the serializer records its type alongside it. EventSauce's repositories
        // pass this header straight into aggregate reconstitution.
        ->and($read->aggregateRootId())->toBeInstanceOf(CheckoutId::class)
        ->and($read->aggregateRootId()?->toString())->toBe($id->toString());
});

it('answers an aggregate that has no messages with an empty stream at version zero', function () {
    $connection = messageRepoConnection();
    $repository = messageRepoFor($connection);

    // Another aggregate's history in the table, so this proves selection rather than emptiness.
    $repository->persist(new Message(messageRepoCheckoutCreated(), [
        Header::AGGREGATE_ROOT_ID => CheckoutId::generate(),
        Header::AGGREGATE_ROOT_VERSION => 1,
    ]));

    [$messages, $version] = messageRepoDrain($repository->retrieveAll(CheckoutId::generate()));

    // Version 0 rather than null or an exception: an unknown id is a *new* aggregate, and
    // this is the value EventSauce hands the caller as "nothing has happened yet".
    expect($messages)->toBe([])
        ->and($version)->toBe(0);
});

it('reports the last message version as the stream version and orders by version', function () {
    $connection = messageRepoConnection();
    $repository = messageRepoFor($connection);
    $id = CheckoutId::generate();

    // Inserted out of order on purpose. Rows are keyed by an auto-incrementing id, so
    // insertion order and version order can differ — a concurrent writer, or a replay that
    // backfills. The version is what the aggregate's optimistic-concurrency check compares
    // against, so reading the stream in insertion order would report the wrong one.
    foreach ([3, 1, 2] as $version) {
        $repository->persist(new Message(messageRepoCheckoutCreated(), [
            Header::AGGREGATE_ROOT_ID => $id,
            Header::AGGREGATE_ROOT_VERSION => $version,
        ]));
    }

    [$messages, $streamVersion] = messageRepoDrain($repository->retrieveAll($id));

    expect(array_map(fn (Message $m) => $m->header(Header::AGGREGATE_ROOT_VERSION), $messages))
        ->toBe([1, 2, 3])
        ->and($streamVersion)->toBe(3);
});

it('excludes the boundary version when retrieving after a version', function () {
    $connection = messageRepoConnection();
    $repository = messageRepoFor($connection);
    $id = CheckoutId::generate();

    foreach ([1, 2, 3] as $version) {
        $repository->persist(new Message($version === 3 ? new CheckoutCancelled : messageRepoCheckoutCreated(), [
            Header::AGGREGATE_ROOT_ID => $id,
            Header::AGGREGATE_ROOT_VERSION => $version,
        ]));
    }

    // Strictly greater than: the snapshotting repository asks for everything *after* the
    // version its snapshot already folded in, so including the boundary would apply that
    // event twice.
    [$messages, $streamVersion] = messageRepoDrain($repository->retrieveAllAfterVersion($id, 2));

    expect(array_map(fn (Message $m) => $m->header(Header::AGGREGATE_ROOT_VERSION), $messages))
        ->toBe([3])
        ->and($messages[0]->payload())->toBeInstanceOf(CheckoutCancelled::class)
        ->and($streamVersion)->toBe(3);
});

it('answers version zero when nothing follows the requested version', function () {
    $connection = messageRepoConnection();
    $repository = messageRepoFor($connection);
    $id = CheckoutId::generate();

    $repository->persist(new Message(messageRepoCheckoutCreated(), [
        Header::AGGREGATE_ROOT_ID => $id,
        Header::AGGREGATE_ROOT_VERSION => 1,
    ]));

    // A snapshot that is fully up to date. The 0 here is why the snapshotting behaviour
    // falls back to the snapshot's own version (`$events->getReturn() ?: ...`) instead of
    // trusting this number blindly.
    [$messages, $streamVersion] = messageRepoDrain($repository->retrieveAllAfterVersion($id, 1));

    expect($messages)->toBe([])
        ->and($streamVersion)->toBe(0);
});

it('mints an event id when the message has none and keeps one it already has', function () {
    $connection = messageRepoConnection();
    $repository = messageRepoFor($connection);
    $id = CheckoutId::generate();
    $given = Uuid::uuid4()->toString();

    $repository->persist(
        new Message(messageRepoCheckoutCreated(), [
            Header::AGGREGATE_ROOT_ID => $id,
            Header::AGGREGATE_ROOT_VERSION => 1,
        ]),
        new Message(messageRepoCheckoutCreated(), [
            Header::AGGREGATE_ROOT_ID => $id,
            Header::AGGREGATE_ROOT_VERSION => 2,
            Header::EVENT_ID => $given,
        ]),
    );

    [$messages] = messageRepoDrain($repository->retrieveAll($id));

    $minted = $messages[0]->header(Header::EVENT_ID);

    // The generated id has to be a real UUID and has to be *stored*, not just returned:
    // the `event_id` column is the stream's idempotency handle, and a caller replaying a
    // message it already sent relies on the id in the payload matching the column.
    expect($minted)->toBeString()
        ->and(Uuid::isValid((string) $minted))->toBeTrue()
        ->and($messages[1]->header(Header::EVENT_ID))->toBe($given)
        ->and($connection->table('stored_events')->orderBy('version')->pluck('event_id')->all())
        ->toBe([$minted, $given]);
});

it('defaults the version column to zero for a message that carries no version', function () {
    $connection = messageRepoConnection();
    $repository = messageRepoFor($connection);
    $id = CheckoutId::generate();

    // Not every message is an aggregate event — a decorator-stamped message dispatched
    // outside a version sequence still has to land somewhere readable rather than fail on
    // a NOT NULL column.
    $repository->persist(new Message(messageRepoCheckoutCreated(), [
        Header::AGGREGATE_ROOT_ID => $id,
    ]));

    expect($connection->table('stored_events')->value('version'))->toBe(0);
});

it('writes nothing when asked to persist no messages', function () {
    $connection = messageRepoConnection();
    $repository = messageRepoFor($connection);

    $repository->persist();

    // The empty-insert guard: without it the builder is handed an empty value list, which is
    // a no-op on some drivers and a syntax error on others.
    expect($connection->table('stored_events')->count())->toBe(0);
});

it('refuses a message that names no aggregate root instead of orphaning it', function () {
    $connection = messageRepoConnection();
    $repository = messageRepoFor($connection);

    // A message with no aggregate root id would be written with an empty id and then match
    // no `retrieveAll()` ever again — present in the table, absent from every history. The
    // raw RuntimeException rather than `UnableToPersistMessages` is the point: this is a
    // programming error on the caller's side, not a storage failure to be retried.
    expect(fn () => $repository->persist(new Message(messageRepoCheckoutCreated())))
        ->toThrow(RuntimeException::class, 'Cannot persist a message that names no aggregate root.');

    expect(fn () => $repository->persist(new Message(messageRepoCheckoutCreated())))
        ->not->toThrow(UnableToPersistMessages::class);

    expect($connection->table('stored_events')->count())->toBe(0);
});

it('wraps a storage failure on write in UnableToPersistMessages', function () {
    // Pointed at a table that does not exist, which is the shape of every real write failure
    // here — a missing migration in the consuming app, or a revoked grant.
    $repository = messageRepoFor(messageRepoConnection(), 'no_such_table');

    expect(fn () => $repository->persist(new Message(messageRepoCheckoutCreated(), [
        Header::AGGREGATE_ROOT_ID => CheckoutId::generate(),
        Header::AGGREGATE_ROOT_VERSION => 1,
    ])))->toThrow(UnableToPersistMessages::class);
});

it('wraps a storage failure on read in UnableToRetrieveMessages', function () {
    $repository = messageRepoFor(messageRepoConnection(), 'no_such_table');

    // Both read paths, because each has its own try/catch and only one of them was ever
    // exercised by the aggregate repositories.
    expect(fn () => $repository->retrieveAll(CheckoutId::generate()))
        ->toThrow(UnableToRetrieveMessages::class)
        ->and(fn () => $repository->retrieveAllAfterVersion(CheckoutId::generate(), 0))
        ->toThrow(UnableToRetrieveMessages::class);
});

it('paginates the whole table in insertion order and advances the cursor', function () {
    $connection = messageRepoConnection();
    $repository = messageRepoFor($connection);
    $first = CheckoutId::generate();
    $second = CheckoutId::generate();

    // Pagination is a cross-aggregate read — it is what a projector rebuilding from scratch
    // uses — so the ids are interleaved to prove it does not filter by aggregate.
    $repository->persist(
        new Message(messageRepoCheckoutCreated(1), [Header::AGGREGATE_ROOT_ID => $first, Header::AGGREGATE_ROOT_VERSION => 1]),
        new Message(messageRepoCheckoutCreated(2), [Header::AGGREGATE_ROOT_ID => $second, Header::AGGREGATE_ROOT_VERSION => 1]),
        new Message(messageRepoCheckoutCreated(3), [Header::AGGREGATE_ROOT_ID => $first, Header::AGGREGATE_ROOT_VERSION => 2]),
    );

    $page = $repository->paginate(OffsetCursor::fromStart(2));
    [$messages, $cursor] = messageRepoDrain($page);

    expect($messages)->toHaveCount(2)
        ->and(array_map(fn (Message $m) => $m->payload()->amount->getAmount(), $messages))->toBe(['1', '2'])
        ->and($cursor)->toBeInstanceOf(OffsetCursor::class);

    // The returned cursor is the row id just consumed, so resuming from it must neither
    // repeat that row nor skip the next one — a projector that re-reads a row double-counts
    // and one that skips a row silently loses an event.
    [$rest, $finalCursor] = messageRepoDrain($repository->paginate($cursor));

    expect(array_map(fn (Message $m) => $m->payload()->amount->getAmount(), $rest))->toBe(['3'])
        ->and($finalCursor->offset())->toBeGreaterThan($cursor->offset());

    // Exhausted: the cursor stops moving, which is how a caller knows to stop.
    [$none, $sameCursor] = messageRepoDrain($repository->paginate($finalCursor));

    expect($none)->toBe([])
        ->and($sameCursor->offset())->toBe($finalCursor->offset());
});
