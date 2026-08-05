<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use EventSauce\EventSourcing\Serialization\ConstructingMessageSerializer;
use EventSauce\EventSourcing\Serialization\MessageSerializer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Techork\PaymentService\Laravel\EventSourcing\Serialization\SymfonyPayloadSerializer;
use Techork\PaymentService\Laravel\Serializer\PayloadSerializerFactory;
use Techork\PaymentService\Laravel\Shredding\PiiStore;

/**
 * The two tables the event-sourcing layer reads and writes, on a throwaway SQLite
 * connection, plus the message serializer the service provider wires around them.
 *
 * Three deliberate choices here.
 *
 * A **fresh connection per call**, rather than the memoised global `Capsule` that
 * {@see \Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository}'s
 * test uses. Those repositories are Eloquent models that resolve their connection off the
 * global resolver and so need `setAsGlobal()`; every class under test here takes a
 * `ConnectionInterface` in its constructor. Handing it one directly means these files never
 * call `setAsGlobal()`, never disturb the capsule another file installed, and cannot pass in
 * isolation while failing in the full suite — which is the failure mode a shared global
 * capsule produces.
 *
 * The **schema is defined here** because neither table's migration ships with the package
 * (the consuming app provides them, as `src/Laravel/README.md` says), so there is nothing to
 * mirror from `database/migrations/`. It is instead the schema
 * {@see \Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateMessageRepository}
 * was inlined against — `eventsauce/message-repository-for-illuminate`'s default table, with
 * string ids rather than binary uuids — and the columns
 * {@see \Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateSnapshotRepository}
 * names in its upsert. `aggregate_root_id` is the snapshot primary key because the upsert
 * conflict target is that column alone, and SQLite needs a unique index to honour it: one
 * snapshot per aggregate is the invariant, and the schema is where it lives.
 *
 * The **serializer comes from {@see PayloadSerializerFactory}**, never from a chain assembled
 * here. Hand-built copies of that chain are how a fixed serializer went on being described as
 * broken; asking the factory means a normalizer added for the stream is one these tests
 * exercise too.
 */
final class EventStreamDatabase
{
    /**
     * A connection with `stored_events` and `aggregate_snapshots`, empty, owned by the caller.
     */
    public static function connection(): ConnectionInterface
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        $connection = $capsule->getConnection();
        $schema = $connection->getSchemaBuilder();

        $schema->create('stored_events', function (Blueprint $table) {
            // Auto-incrementing `id` is what `paginate()` walks and returns as its cursor
            // offset, so the stream's pagination order is insertion order, not version order.
            $table->id();
            // Unique: the repository mints a UUIDv7 per message and an event id is the
            // stream's idempotency handle. A duplicate has to fail loudly rather than
            // append a second copy of an event that already happened.
            $table->uuid('event_id')->unique();
            $table->uuid('aggregate_root_id')->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->json('payload');
            $table->index(['aggregate_root_id', 'version']);
        });

        $schema->create('aggregate_snapshots', function (Blueprint $table) {
            $table->uuid('aggregate_root_id')->primary();
            $table->unsignedInteger('aggregate_root_version');
            $table->json('state');
            $table->timestamp('created_at')->nullable();
        });

        return $connection;
    }

    /**
     * The message serializer `GatewayServiceProvider` binds: EventSauce's constructing
     * serializer over {@see SymfonyPayloadSerializer} over the production normalizer chain.
     */
    public static function messageSerializer(?PiiStore $store = null): MessageSerializer
    {
        return new ConstructingMessageSerializer(
            payloadSerializer: new SymfonyPayloadSerializer(
                PayloadSerializerFactory::make($store ?? new EventStreamPiiStore),
            ),
        );
    }
}
