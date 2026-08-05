<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Encryption\Encrypter;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Models\Gateway;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayCredentialRepository;

/**
 * Two lines, and every payment goes through one of them: this is how a GatewayId taken off a
 * request becomes the credentials the factory builds a gateway from.
 *
 * What is worth pinning is the `findOrFail` — the failure mode, not the success. A repository
 * answering null for an unknown gateway would push the decision into the factory, which
 * would take the missing credential for a registration problem and report it as one. The
 * typed miss is the contract, and it is the difference between "this gateway does not exist"
 * and a nulled-out request going to whichever provider answered first.
 *
 * Real repository, real model, real encrypted cast, in-memory SQLite through the shared
 * Capsule — the same harness as the sibling repository tests.
 */
function gatewayCredentialRepositorySchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

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

    Capsule::table('gateways')->delete();
}

beforeEach(function () {
    gatewayCredentialRepositorySchema();

    $this->repository = new EloquentGatewayCredentialRepository;
});

it('resolves a gateway id into the credentials the factory initialises with', function () {
    // The path a charge takes: an id off the request, credentials out of the row. The
    // decrypted bag has to arrive intact — a gateway initialised from a partial one authorises
    // nothing and reports it as a provider error.
    $row = Gateway::query()->create([
        'name' => 'Primary',
        'gateway_name' => 'ConnexPay',
        'credentials' => ['username' => 'merchant', 'password' => 's3cret'],
    ]);

    $credential = $this->repository->findOrFail($row->getId());

    expect($credential)->toBeInstanceOf(GatewayCredential::class)
        ->and($credential->getId()->toString())->toBe($row->getKey())
        ->and($credential->getGatewayName())->toBe('ConnexPay')
        ->and($credential->getCredentials())->toBe(['username' => 'merchant', 'password' => 's3cret']);
});

it('fails loudly for a gateway id with no row behind it', function () {
    // `findOrFail`, and the reason it is not `find`: a caller handed a null credential would
    // either dereference it or treat the gateway as unregistered. Neither says what happened.
    Gateway::query()->create([
        'name' => 'Primary',
        'gateway_name' => 'ConnexPay',
        'credentials' => ['username' => 'u'],
    ]);

    expect(fn () => $this->repository->findOrFail(GatewayId::generate()))
        ->toThrow(ModelNotFoundException::class);
});

it('hands back every configured gateway, and nothing when there are none', function () {
    // `all()` feeds the routing pass that picks a gateway for a payment, so it has to see
    // every row rather than a page of them — and an empty table is a valid answer, not a
    // failure: a tenant with no gateway configured simply has nowhere to send a charge.
    expect(iterator_to_array($this->repository->all()))->toBe([]);

    foreach (['ConnexPay', 'Nuvei', 'Stripe'] as $gatewayName) {
        Gateway::query()->create([
            'name' => $gatewayName.' account',
            'gateway_name' => $gatewayName,
            'credentials' => ['username' => 'u'],
        ]);
    }

    $names = [];
    foreach ($this->repository->all() as $credential) {
        $names[] = $credential->getGatewayName();
    }

    sort($names);

    expect($names)->toBe(['ConnexPay', 'Nuvei', 'Stripe']);
});
