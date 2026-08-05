<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\DefaultSignatureValidator;
use Spatie\WebhookClient\WebhookConfig;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Webhook\Enum\WebhookCallStatus;
use Techork\PaymentService\Laravel\Webhook\Job\ProcessWebhookJob;
use Techork\PaymentService\Laravel\Webhook\Model\WebhookCall;
use Techork\PaymentService\Laravel\Webhook\Profile\IdempotencyProfile;

/**
 * The storage record every delivery passes through, at zero coverage until now — and it
 * held a defect that only a caller could have found. `pending()` is a `#[Scope]` method,
 * and Laravel routes a scope called statically through `__callStatic`, which PHP reaches
 * only for an INACCESSIBLE method. A `public` scope therefore makes `WebhookCall::pending()`
 * an `Error: Non-static method ... cannot be called statically` for the first person who
 * writes the obvious thing. Nothing called it that way yet, so nothing said so. It is
 * `protected` now, and the two tests below pin both call styles so the visibility cannot
 * drift back.
 *
 * The rest of the file is the writing side: `storeWebhook()` is the only place the
 * machinery-internal metadata that
 * {@see \Techork\PaymentService\Laravel\Webhook\Bridge\SpatieSignatureValidatorAdapter}
 * stashes on the request becomes a row, and the `mark*` methods are the only place a
 * status is decided. Both are pure Eloquent behaviour — casts, mass assignment, a JSON
 * column — so they are exercised against a real database rather than asserted on
 * attributes in memory: what matters is what comes back out of the row the queue reads.
 *
 * Same harness as the repository tests: one global in-memory SQLite Capsule, real classes,
 * no new dev dependency. The table is created only when absent and emptied per test,
 * because the Capsule is shared across every database-backed file in this process.
 */
function bootWebhookCallModelSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors spatie's create_webhook_calls_table plus this package's
    // extend_webhook_calls, including the composite unique key — the DB-level half of the
    // invariant {@see IdempotencyProfile} enforces in PHP, which is asserted below.
    //
    // One deliberate deviation: `id` is a uuid here, while spatie's published migration
    // declares `bigIncrements`. The model uses `HasUuids`, so it generates a uuid for the
    // primary key and the stock column cannot accept it. See the note on the key-type test.
    if (! Capsule::schema()->hasTable('webhook_calls')) {
        Capsule::schema()->create('webhook_calls', function ($table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('gateway_id')->nullable();
            $table->string('external_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->string('url', 512);
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->json('attachments')->nullable();
            $table->text('exception')->nullable();
            $table->timestamps();

            $table->unique(['name', 'external_id']);
        });
    }

    Capsule::table('webhook_calls')->delete();
}

/**
 * The single shared spatie config entry this package drives. `signature_validator` and
 * `webhook_profile` are resolved through the container by spatie's constructor, so the
 * cheapest autowirable pair stands in — `storeWebhook()` reads only `name` and
 * `storeHeaders`.
 */
function webhookCallConfig(string $name = 'payments'): WebhookConfig
{
    return new WebhookConfig([
        'name' => $name,
        'signing_secret' => 'not-used-per-tenant-credentials-carry-it',
        'signature_header_name' => 'Signature',
        'signature_validator' => DefaultSignatureValidator::class,
        'webhook_profile' => IdempotencyProfile::class,
        'webhook_model' => WebhookCall::class,
        'process_webhook_job' => ProcessWebhookJob::class,
        'store_headers' => ['Signature'],
    ]);
}

/**
 * @param  array<string, mixed>|null  $meta  what the signature-validator bridge stashed;
 *                                           null means it stashed nothing
 */
function webhookCallRequest(?array $meta, string $body = '{"id":"evt_1"}'): Request
{
    $request = Request::create(
        'https://app.test/webhooks/payments',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE' => 'sig-1', 'HTTP_X_UNRELATED' => 'noise'],
        $body,
    );

    if ($meta !== null) {
        $request->attributes->set(WebhookCall::REQUEST_META_ATTRIBUTE, $meta);
    }

    return $request;
}

beforeEach(function () {
    bootWebhookCallModelSchema();

    $this->gatewayId = GatewayId::generate();
});

it('stores the identified tenant, kind and idempotency key the bridge resolved', function () {
    // `name` is repurposed to carry the gateway KIND rather than spatie's config entry name:
    // with one shared entry the config name is a constant, and the kind is what routing and
    // dedup are keyed on. Storing the config name here would make every delivery look like
    // the same gateway to the queue.
    $call = WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(),
        'kind' => 'stripe',
        'external_id' => 'evt_1',
    ]));

    $stored = WebhookCall::query()->findOrFail($call->getKey());

    expect($stored->name)->toBe('stripe')
        ->and($stored->external_id)->toBe('evt_1')
        ->and($stored->status)->toBe(WebhookCallStatus::Pending)
        ->and($stored->processed_at)->toBeNull()
        ->and($stored->url)->toBe('https://app.test/webhooks/payments')
        ->and($stored->payload)->toBe(['id' => 'evt_1'])
        // Only the configured headers are kept: the rest of an inbound request is noise we
        // would otherwise persist forever next to a payment.
        ->and($stored->headers)->toBe(['signature' => ['sig-1']]);
});

it('hands the gateway id back as the value object the queue needs', function () {
    // The job reads this column straight into `StoredWebhookCall`'s `GatewayId` parameter.
    // A cast that answered with the raw string would be a TypeError on the only path the
    // job exists for, which is the same failure mode this neighbourhood already shipped once.
    $call = WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(),
        'kind' => 'nuvei',
        'external_id' => 'sale:1234',
    ]));

    $stored = WebhookCall::query()->findOrFail($call->getKey());

    expect($stored->gateway_id)->toBeInstanceOf(GatewayId::class)
        ->and($stored->gateway_id->toString())->toBe($this->gatewayId->toString());
});

it('falls back to the config name and no tenant when nothing was stashed', function () {
    // Reachable whenever the row is written outside the verified path — a replayed
    // request, a validator that answered true without stashing. The row must still be
    // storable, because that is what lets the job mark it skipped with a reason instead of
    // dying on a null; see ProcessWebhookJobTest.
    WebhookCall::storeWebhook(webhookCallConfig('payments'), webhookCallRequest(null));

    $stored = WebhookCall::query()->firstOrFail();

    expect($stored->name)->toBe('payments')
        ->and($stored->gateway_id)->toBeNull()
        ->and($stored->external_id)->toBeNull()
        ->and($stored->status)->toBe(WebhookCallStatus::Pending);
});

it('refuses a second row for the same kind and idempotency key', function () {
    // The DB-level half of the dedup invariant. {@see IdempotencyProfile} checks the same
    // pair before storing, but two deliveries of one event can race between the check and
    // the insert; the unique index is what makes the loser fail instead of enqueueing a
    // duplicate job that would replay a capture.
    $meta = ['gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => 'evt_dup'];

    WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest($meta));

    expect(fn () => WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest($meta)))
        ->toThrow(QueryException::class);
});

it('lets the same idempotency key through for a different gateway kind', function () {
    // Providers number their events independently, so `1` from one gateway and `1` from
    // another are different deliveries. Scoping dedup on the pair rather than the id alone
    // is what keeps the second one from being dropped as a duplicate.
    WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => '1',
    ]));
    WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'nuvei', 'external_id' => '1',
    ]));

    expect(WebhookCall::query()->count())->toBe(2);
});

it('filters to the pending calls as a builder scope', function () {
    WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => 'evt_pending',
    ]));
    $processed = WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => 'evt_done',
    ]));
    $processed->markProcessed();

    expect(WebhookCall::query()->pending()->pluck('external_id')->all())->toBe(['evt_pending']);
});

it('filters to the pending calls when the scope is called statically', function () {
    // The regression this file exists for. Laravel serves a static scope call through
    // `__callStatic`, which PHP only enters for a method it cannot access from outside —
    // so this line is an `Error` for a `public` scope and works for a `protected` one. It
    // is the natural way to write the call, which is exactly why it must not be the
    // broken one.
    WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => 'evt_pending',
    ]));
    $skipped = WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => 'evt_skipped',
    ]));
    $skipped->markSkipped('not ours');

    expect(WebhookCall::pending()->pluck('external_id')->all())->toBe(['evt_pending'])
        ->and(WebhookCall::pending()->count())->toBe(1);
});

it('keeps the scope inaccessible from outside, which is what makes the static call work', function () {
    // Pins the cause rather than only the symptom: a rename to a `public` scope would keep
    // the builder test green and break the static one in a way whose reason is invisible at
    // the call site. Asserted through reflection so the message here is the explanation.
    $method = new ReflectionMethod(WebhookCall::class, 'pending');

    expect($method->isPublic())->toBeFalse('a public #[Scope] method cannot be called statically')
        ->and($method->getAttributes(Scope::class))->not->toBeEmpty();
});

it('records a processed call with the moment it was applied', function () {
    $call = WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => 'evt_ok',
    ]));

    $call->markProcessed();
    $stored = WebhookCall::query()->findOrFail($call->getKey());

    // `processed_at` is what tells an operator a terminal status was reached here rather
    // than seeded by the column default.
    expect($stored->status)->toBe(WebhookCallStatus::Processed)
        ->and($stored->processed_at)->not->toBeNull()
        ->and($stored->exception)->toBeNull();
});

it('records why a call was skipped', function () {
    $call = WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => 'evt_skip',
    ]));

    $call->markSkipped('Handler reported skip');
    $stored = WebhookCall::query()->findOrFail($call->getKey());

    // Skipped is a decision, not a failure, and the reason column is the only record of
    // which decision it was — the difference between "no handler for this event type" and
    // "the aggregate had already moved on".
    expect($stored->status)->toBe(WebhookCallStatus::Skipped)
        ->and($stored->processed_at)->not->toBeNull()
        ->and($stored->exception)->toBe(['message' => 'Handler reported skip']);
});

it('leaves an already recorded reason alone when skipped without one', function () {
    // A reason is optional, and absent must mean "say nothing" rather than "erase what is
    // there": a call that failed and was later skipped keeps the diagnosis that explains it.
    $call = WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => 'evt_quiet',
    ]));
    $call->markFailed(new RuntimeException('gateway timed out'));

    $call->markSkipped();
    $stored = WebhookCall::query()->findOrFail($call->getKey());

    expect($stored->status)->toBe(WebhookCallStatus::Skipped)
        ->and($stored->exception['message'])->toBe('gateway timed out');
});

it('records the diagnosis of a failed call', function () {
    $call = WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => 'evt_boom',
    ]));

    $call->markFailed(new RuntimeException('issuer unreachable', 503));
    $stored = WebhookCall::query()->findOrFail($call->getKey());

    // The stack trace goes in because this row is what a webhook is debugged from once the
    // queue has given up; the code and message go in separately so they can be searched.
    expect($stored->status)->toBe(WebhookCallStatus::Failed)
        ->and($stored->exception['code'])->toBe(503)
        ->and($stored->exception['message'])->toBe('issuer unreachable')
        ->and($stored->exception['trace'])->toBeString()
        ->and($stored->processed_at)->not->toBeNull();
});

it('generates its own string primary key', function () {
    // `HasUuids` means the model, not the database, decides the id — and that it is a
    // string. Worth pinning because the column type must agree: spatie's published
    // create_webhook_calls_table declares `bigIncrements('id')`, which cannot hold what
    // this model generates, and no migration in this package alters it. The harness above
    // uses a uuid column so the rest of these tests describe the model rather than that
    // mismatch.
    $call = WebhookCall::storeWebhook(webhookCallConfig(), webhookCallRequest([
        'gateway_id' => $this->gatewayId->toString(), 'kind' => 'stripe', 'external_id' => 'evt_id',
    ]));

    expect($call->getKeyType())->toBe('string')
        ->and($call->getIncrementing())->toBeFalse()
        ->and($call->getKey())->toBeString()
        ->and(Ramsey\Uuid\Uuid::isValid($call->getKey()))->toBeTrue();
});
