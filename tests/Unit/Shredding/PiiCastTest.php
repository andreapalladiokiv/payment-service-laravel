<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Techork\PaymentService\Laravel\Casts\PiiCast;
use Techork\PaymentService\Laravel\Shredding\EloquentPiiStore;
use Techork\PaymentService\Laravel\Shredding\PiiStore;

final class TestPiiProjection extends Model
{
    protected $table = 'pii_projections';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'email' => PiiCast::class.':redacted@example.com',
        'mobile' => PiiCast::class.':+0000000000,mobile_e164_hash',
    ];
}

function bootPiiCastTestSchema(): ConnectionInterface
{
    $container = new Container;
    Container::setInstance($container);
    $container->singleton(PiiStore::class, EloquentPiiStore::class);

    $capsule = new Capsule($container);
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    /** @var ConnectionInterface $connection */
    $connection = $capsule->getConnection();
    $schema = $connection->getSchemaBuilder();

    $schema->create('shredding_values', function (Blueprint $table): void {
        $table->string('hash', 64)->primary();
        $table->text('value');
        $table->timestamp('created_at')->nullable();
    });
    $schema->create('pii_projections', function (Blueprint $table): void {
        $table->id();
        $table->string('email_hash', 64)->nullable();
        $table->string('mobile_e164_hash', 64)->nullable();
    });

    return $connection;
}

it('resolves plaintext via the PiiStore using the conventional *_hash column', function () {
    $connection = bootPiiCastTestSchema();
    /** @var PiiStore $store */
    $store = app(PiiStore::class);
    $hash = $store->store('alice@example.com');

    $connection->table('pii_projections')->insert(['email_hash' => $hash]);

    expect(TestPiiProjection::query()->first()->email)->toBe('alice@example.com');
});

it('honours the second cast argument as an explicit hash-column override', function () {
    $connection = bootPiiCastTestSchema();
    $hash = app(PiiStore::class)->store('+15555550199');

    $connection->table('pii_projections')->insert(['mobile_e164_hash' => $hash]);

    expect(TestPiiProjection::query()->first()->mobile)->toBe('+15555550199');
});

it('returns the stub when the hash column is null', function () {
    $connection = bootPiiCastTestSchema();
    $connection->table('pii_projections')->insert(['email_hash' => null]);

    expect(TestPiiProjection::query()->first()->email)->toBe('redacted@example.com');
});

it('returns the stub when the hash has no matching row in the store', function () {
    $connection = bootPiiCastTestSchema();

    // A dangling hash — points nowhere in `shredding_values`. Equivalent to
    // a row that was shredded out by a compliance request from a different
    // process (so the PiiStore in-process cache wasn't primed).
    $dangling = str_repeat('a', 64);
    $connection->table('pii_projections')->insert(['email_hash' => $dangling]);

    $row = TestPiiProjection::query()->first();
    expect($row->email)->toBe('redacted@example.com')
        ->and($row->email_hash)->toBe($dangling, 'forensic hash linkage survives shred');
});

it('throws on write — PII mutations must go through domain events', function () {
    bootPiiCastTestSchema();

    $row = new TestPiiProjection;
    expect(fn () => $row->email = 'new@example.com')
        ->toThrow(LogicException::class, 'read-only');
});
