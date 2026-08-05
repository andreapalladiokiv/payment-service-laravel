<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Models\Gateway;
use Techork\PaymentService\Laravel\Models\GatewayReference;

/**
 * The `gateways` row is where every payment starts: {@see GatewayCredential} is implemented
 * here and nowhere else, and the factory reads a provider name plus a credentials array off
 * it to build the gateway that talks to the network. All of that was at zero coverage.
 *
 * Two things in this model are easy to get wrong and impossible to see from reading it.
 * The first is the `encrypted:json` cast: a credentials bag written in plaintext is a PCI
 * finding, and nothing about a passing suite would say which one landed in the column — so
 * the raw column is inspected here, not only the accessor. The second is `getKey()`: the
 * `id` cast hands back a {@see GatewayId}, and Eloquent's own `getKey()` would return that
 * object into every query, relation and cache key built from it. The override that turns it
 * back into a string is a one-liner with the whole routing layer behind it.
 *
 * Same harness as the repository tests: one shared in-memory SQLite Capsule, real models,
 * nothing mocked. `Model::encryptUsing` replaces the `Crypt` facade so the cast runs for
 * real without booting an application container.
 */
function gatewayModelSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // A real encrypter rather than the facade: the cast is half of what this file asserts,
    // so stubbing it out would leave the plaintext question unanswered.
    Model::encryptUsing(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));

    // Mirrors create_gateways_table.
    if (! Capsule::schema()->hasTable('gateways')) {
        Capsule::schema()->create('gateways', function ($table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('gateway_name');
            $table->text('credentials');
            $table->timestamps();
        });
    }

    // Mirrors create_gateway_references_table + add_metadata_to_gateway_references, for the
    // sake of the hasMany only. Foreign keys are left off: this exercises the model.
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
    Capsule::table('gateways')->delete();
}

/**
 * @param  array<string, string>  $credentials
 */
function gatewayModelRow(string $gatewayName = 'ConnexPay', array $credentials = ['username' => 'u', 'password' => 'p'], ?string $label = 'Primary'): Gateway
{
    return Gateway::query()->create([
        'name' => $label,
        'gateway_name' => $gatewayName,
        'credentials' => $credentials,
    ]);
}

beforeEach(fn () => gatewayModelSchema());

it('never writes a credential bag to the column in plaintext', function () {
    // The reason the cast is `encrypted:json` and not `json`. Asserted against the raw
    // column because the accessor answers the same either way, so an accidental downgrade
    // of the cast would pass every other test in this package.
    $gateway = gatewayModelRow(credentials: ['username' => 'merchant', 'password' => 's3cret']);

    $stored = Capsule::table('gateways')->where('id', $gateway->getKey())->value('credentials');

    expect($stored)->toBeString()
        ->and($stored)->not->toContain('s3cret')
        ->and($stored)->not->toContain('merchant')
        ->and($gateway->fresh()->getCredentials())->toBe(['username' => 'merchant', 'password' => 's3cret']);
});

it('hands out a string key even though its id is a value object', function () {
    // `getKey()` feeds query builders, relations and route binding, and the `id` cast makes
    // the attribute a GatewayId. Without the override those callers receive an object where
    // they bind a string — which SQLite would reject and PDO could not stringify.
    $gateway = gatewayModelRow();

    expect($gateway->getKey())->toBeString()
        ->and($gateway->getId())->toBeInstanceOf(GatewayId::class)
        // The identity the factory caches its gateway instances under has to be the same
        // one the row is stored beneath.
        ->and($gateway->getId()->toString())->toBe($gateway->getKey())
        ->and(Gateway::query()->find($gateway->getKey()))->not->toBeNull();
});

it('answers the credential contract with the provider name, not the tenant label', function () {
    // `getGatewayName()` is the key the gateway registry and `services.{gateway_name}` are
    // both spelled with, while `name` is a human label a tenant can set to anything. Two
    // rows for the same provider under different labels must still resolve one provider.
    $gateway = gatewayModelRow('Nuvei', label: 'EU acquirer');

    expect($gateway)->toBeInstanceOf(GatewayCredential::class)
        ->and($gateway->getGatewayName())->toBe('Nuvei')
        ->and($gateway->name)->toBe('EU acquirer');
});

it('keeps its references to itself', function () {
    // The hasMany is keyed on `gateway_id`, and the parent side of that key comes out of the
    // value-object cast. One tenant reading another's transaction references would be the
    // worst possible outcome of getting that wrong, so the negative half is asserted too.
    $mine = gatewayModelRow();
    $theirs = gatewayModelRow('Nuvei', label: 'Other');

    foreach ([[$mine, 'ref_mine'], [$theirs, 'ref_theirs']] as [$gateway, $reference]) {
        GatewayReference::query()->forceCreate([
            'gateway_id' => $gateway->getKey(),
            'referenceable_type' => 'token',
            'referenceable_id' => Uuid::uuid4()->toString(),
            'reference' => $reference,
        ]);
    }

    expect($mine->references()->pluck('reference')->all())->toBe(['ref_mine'])
        ->and($theirs->references()->pluck('reference')->all())->toBe(['ref_theirs']);
});

it('mints its own id rather than accepting one through mass assignment', function () {
    // `$fillable` lists the three columns a tenant supplies and deliberately omits `id`, so
    // a payload carrying one cannot claim another tenant's gateway id. HasUuids generates
    // the key instead, and the cast still reads it back as a GatewayId.
    $planted = Uuid::uuid4()->toString();

    $gateway = Gateway::query()->create([
        'id' => $planted,
        'name' => 'Primary',
        'gateway_name' => 'ConnexPay',
        'credentials' => ['username' => 'u'],
    ]);

    expect($gateway->getKey())->not->toBe($planted)
        ->and($gateway->getId())->toBeInstanceOf(GatewayId::class);
});
