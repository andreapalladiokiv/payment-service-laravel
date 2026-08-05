<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Techork\PaymentService\Laravel\Models\ShreddingValue;

/**
 * A content-addressed row: the primary key IS the sha256 of the plaintext, which is what
 * makes crypto-shredding work — read models store the hash, deleting this row destroys the
 * PII everywhere at once, and storing the same value twice must land on the same key rather
 * than leaving a second copy behind.
 *
 * Everything here derives the key from an attribute, so the interesting cases are the ones
 * where that attribute is not there yet. Both guards are recent and neither is reachable
 * from the store: `newUniqueId()` throws instead of hashing null, and `isValidUniqueId()`
 * answers false instead of the same. Without them the calls are a TypeError under
 * `strict_types` — a fatal raised inside Eloquent's insert path, i.e. during a payment.
 *
 * Booted through the shared in-memory SQLite Capsule with the real model and the real
 * schema, because `getUpdatedAtColumn()` returning null is only meaningful against a table
 * that genuinely has no `updated_at` column.
 */
function shreddingValueModelSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors create_shredding_values_table exactly, `updated_at` absent included: that
    // absence is what the getUpdatedAtColumn() override answers to.
    if (! Capsule::schema()->hasTable('shredding_values')) {
        Capsule::schema()->create('shredding_values', function ($table) {
            $table->string('hash', 64)->primary();
            $table->text('value');
            $table->timestamp('created_at')->nullable();
        });
    }

    Capsule::table('shredding_values')->delete();
}

beforeEach(fn () => shreddingValueModelSchema());

it('keys itself on the sha256 of its plaintext', function () {
    // Content addressing, stated as the equality the read models depend on: a hash column
    // elsewhere is only resolvable if this row's key is exactly sha256(plaintext).
    $value = new ShreddingValue;
    $value->value = 'holder@example.test';
    $value->save();

    expect($value->hash)->toBe(hash('sha256', 'holder@example.test'))
        ->and($value->getKey())->toBe(hash('sha256', 'holder@example.test'))
        ->and(Capsule::table('shredding_values')->where('hash', $value->hash)->value('value'))
        ->toBe('holder@example.test');
});

it('gives two instances of the same plaintext the same id and different plaintexts different ids', function () {
    // Determinism is what lets the store deduplicate instead of accumulating a row per
    // request, and the negative half is what stops two people's PII sharing one row.
    $first = new ShreddingValue;
    $first->value = 'same@example.test';
    $second = new ShreddingValue;
    $second->value = 'same@example.test';
    $other = new ShreddingValue;
    $other->value = 'other@example.test';

    expect($first->newUniqueId())->toBe($second->newUniqueId())
        ->and($other->newUniqueId())->not->toBe($first->newUniqueId());
});

it('refuses to derive an id when it has no plaintext to derive it from', function () {
    // The guard, called directly. Hashing a null would be a TypeError here; hashing an
    // empty string would be worse — a row keyed on sha256('') that every other valueless
    // instance would then collide with and read someone else's plaintext out of.
    expect(fn () => new ShreddingValue()->newUniqueId())
        ->toThrow(RuntimeException::class, 'A shredding value needs its plaintext before an id can be derived from it.');
});

it('refuses the insert itself when the plaintext is missing', function () {
    // Where that guard actually fires: Eloquent asks for the unique id from inside
    // performInsert, so a valueless row cannot reach the table at all. The refusal is loud
    // and the table stays clean.
    expect(fn () => new ShreddingValue()->save())->toThrow(RuntimeException::class)
        ->and(Capsule::table('shredding_values')->count())->toBe(0);
});

it('stamps created_at and never looks for an updated_at column', function () {
    // The table has no `updated_at`. With the override gone, Eloquent's insert would name a
    // column that does not exist and the write would fail — while `created_at` is kept,
    // since knowing when PII entered the store is part of a retention answer.
    $value = new ShreddingValue;
    $value->value = 'retained@example.test';
    $value->save();

    expect(new ShreddingValue()->getUpdatedAtColumn())->toBeNull()
        ->and($value->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and(array_keys((array) Capsule::table('shredding_values')->first()))
        ->toBe(['hash', 'value', 'created_at']);
});

it('will not verify a route-bound id it has no plaintext to check against', function () {
    // Route binding is the only caller of isValidUniqueId, and it hands the check a fresh
    // instance — nothing to compare, so the answer is false and the binding misses. Pinned
    // as the alternative to a TypeError thrown out of the router on a hash-shaped URL
    // segment; PII must never be reachable by guessing one either way.
    expect(fn () => new ShreddingValue()->resolveRouteBinding(hash('sha256', 'anything')))
        ->toThrow(ModelNotFoundException::class);
});

it('verifies a route-bound id against the plaintext it does hold', function () {
    // The other branch of the same guard: when the instance knows its plaintext, a matching
    // id resolves and a mismatched one is refused before any query runs.
    $stored = new ShreddingValue;
    $stored->value = 'bound@example.test';
    $stored->save();

    $subject = new ShreddingValue;
    $subject->value = 'bound@example.test';

    expect($subject->resolveRouteBinding(hash('sha256', 'bound@example.test'))?->hash)->toBe($stored->hash)
        ->and(fn () => $subject->resolveRouteBinding(hash('sha256', 'someone else')))
        ->toThrow(ModelNotFoundException::class);
});
