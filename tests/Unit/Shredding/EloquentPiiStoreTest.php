<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Techork\PaymentService\Laravel\Models\ShreddingValue;
use Techork\PaymentService\Laravel\Shredding\EloquentPiiStore;

/**
 * The store behind crypto-shredding: every serialized event carries hashes instead of PII,
 * and this class is the only thing that can turn one back into a name, an email or an
 * address. Three properties hold that scheme up and all three were unexecuted.
 *
 * A round trip that does not return the same plaintext means a stored event can never be
 * read back. A hash that is not deterministic means the same person accumulates a row per
 * request and shredding one leaves the others. An unknown hash that does not answer null
 * means a shredded — deliberately deleted — value cannot be told apart from a present one,
 * and the normalizer has nothing to put in the payload.
 *
 * Written against the real model and a real in-memory SQLite table, sharing the Capsule with
 * the other database-backed tests here. A double would have to reimplement `createOrFirst`
 * and the primary key that makes it idempotent, which is the entire behaviour under test.
 *
 * NOT covered on purpose: retrieving a hash that misses and then storing that same plaintext
 * through the same instance. That sequence currently returns the hash without writing a row —
 * reported separately; a test asserting either outcome would fix the behaviour by decree.
 */
function piiStoreSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors create_shredding_values_table.
    if (! Capsule::schema()->hasTable('shredding_values')) {
        Capsule::schema()->create('shredding_values', function ($table) {
            $table->string('hash', 64)->primary();
            $table->text('value');
            $table->timestamp('created_at')->nullable();
        });
    }

    Capsule::table('shredding_values')->delete();
}

beforeEach(fn () => piiStoreSchema());

it('returns the same plaintext for the hash it handed out', function () {
    // The round trip, and the whole promise of the interface: a payload written with these
    // hashes has to be readable again.
    $store = new EloquentPiiStore;

    $hash = $store->store('Grace Hopper');

    expect($store->retrieve($hash))->toBe('Grace Hopper');
});

it('gives the same plaintext one hash and one row, whoever asks', function () {
    // Determinism across instances, because storing happens once per request while shredding
    // happens once per person: a second row for the same value would survive the deletion of
    // the first and leave the PII readable through whichever event referenced it.
    $first = new EloquentPiiStore;
    $second = new EloquentPiiStore;

    $hash = $first->store('holder@example.test');
    $again = $first->store('holder@example.test');
    $elsewhere = $second->store('holder@example.test');

    expect($again)->toBe($hash)
        ->and($elsewhere)->toBe($hash)
        ->and(Capsule::table('shredding_values')->count())->toBe(1);
});

it('answers null for a hash it has never stored', function () {
    // What a shredded value looks like from the read side. Null is a value the normalizers
    // handle; anything else — an exception, an empty string — would either break replaying a
    // historical event or write a blank where a name used to be.
    $store = new EloquentPiiStore;
    $store->store('present@example.test');

    expect($store->retrieve(hash('sha256', 'shredded@example.test')))->toBeNull()
        ->and($store->retrieve('not-even-a-hash'))->toBeNull();
});

it('reads a value another request stored', function () {
    // The in-memory cache is a cache, not the source of truth. Every read of a stored event
    // happens in a process that never stored anything, so a miss has to fall through to the
    // table — and a value shredded out of that table has to stop resolving.
    $writer = new EloquentPiiStore;
    $hash = $writer->store('4111 Main Street');

    $reader = new EloquentPiiStore;
    expect($reader->retrieve($hash))->toBe('4111 Main Street');

    Capsule::table('shredding_values')->where('hash', $hash)->delete();
    expect(new EloquentPiiStore()->retrieve($hash))->toBeNull();
});

it('keeps distinct plaintexts distinct, differing by one character', function () {
    // Guards against any normalising of the input on the way in: two people whose details
    // differ slightly must not collapse onto one row, which a trim, a case fold or a
    // truncation would cause — and one shred would then take both.
    $store = new EloquentPiiStore;

    $one = $store->store('holder@example.test');
    $two = $store->store('holder@example.tes');

    expect($one)->not->toBe($two)
        ->and(Capsule::table('shredding_values')->count())->toBe(2)
        ->and($store->retrieve($one))->toBe('holder@example.test')
        ->and($store->retrieve($two))->toBe('holder@example.tes');
});

it('serves a plaintext it already stored without going back to the database', function () {
    // Why the cache exists: one payload repeats the same PII across billing address, holder
    // and customer fields, and the normalizer stores each of them separately. Pinned on the
    // query log because "it works either way" is exactly how a per-field round trip to the
    // database gets in unnoticed.
    $store = new EloquentPiiStore;
    $store->store('Grace Hopper');

    Capsule::connection()->flushQueryLog();
    Capsule::connection()->enableQueryLog();
    $repeat = $store->store('Grace Hopper');
    $queries = Capsule::connection()->getQueryLog();
    Capsule::connection()->disableQueryLog();

    expect($repeat)->toBe(hash('sha256', 'Grace Hopper'))
        ->and($queries)->toBe([]);
});

// ─────────────────────────────────────────────────────────
//  A miss must not be remembered as an answer
// ─────────────────────────────────────────────────────────

it('stores a value whose hash was looked up and missed beforehand', function () {
    // The sequence that silently lost PII. `retrieve()` used `??=`, which ASSIGNS null on a
    // miss, so the hash gained a key in the memo; `store()` gated on array_key_exists and
    // returned that hash without writing a row. It reported success, the normalizer wrote the
    // hash into the event payload, and the field read as shredded for good — from this instance
    // and from every later one.
    //
    // Reachable rather than theoretical: a value erased under GDPR retrieves as null, and the
    // next event carrying the same plaintext stores it again.
    $store = new EloquentPiiStore;
    $plaintext = 'ADA LOVELACE';
    $hash = hash('sha256', $plaintext);

    expect($store->retrieve($hash))->toBeNull();

    $returned = $store->store($plaintext);

    expect($returned)->toBe($hash)
        ->and(Capsule::table('shredding_values')->count())->toBe(1)
        ->and($store->retrieve($hash))->toBe($plaintext)
        // The one that matters: another instance, i.e. another request, must find it too.
        ->and((new EloquentPiiStore)->retrieve($hash))->toBe($plaintext);
});

it('keeps re-reading a hash that is genuinely absent', function () {
    // The other half of not memoising misses. A miss is not a stable answer — a store in the
    // same request can make it resolvable — so answering null must not become permanent.
    $store = new EloquentPiiStore;
    $hash = hash('sha256', 'NEVER STORED');

    expect($store->retrieve($hash))->toBeNull()
        ->and($store->retrieve($hash))->toBeNull();

    ShreddingValue::query()->create(['value' => 'NEVER STORED']);

    expect($store->retrieve($hash))->toBe('NEVER STORED');
});
