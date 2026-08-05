<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Webhook\Enum\WebhookCallStatus;
use Techork\PaymentService\Laravel\Webhook\Model\WebhookCall;
use Techork\PaymentService\Laravel\Webhook\Profile\IdempotencyProfile;

/**
 * The gate that decides whether a delivery is stored and queued at all, at zero coverage
 * until now. Its answer is a single boolean over a database lookup, which is why it has to
 * be exercised against a real table: the WHERE clause IS the invariant, and getting it wrong
 * is not a missing feature but a double application — a redelivered capture replayed against
 * an aggregate, or a genuine event dropped because something else claimed its id.
 *
 * Two sides worth stating separately, because they fail in opposite directions:
 *
 *  - already seen means the (kind, external_id) PAIR is stored. Not the id alone: providers
 *    number their events independently, so one gateway's `1` must not shadow another's.
 *  - unattributable means REFUSE. Both fields arrive from the signature-validator bridge via
 *    request attributes, so their absence means no tenant was identified — there is nothing
 *    to dedup on and nothing that could be routed later, and letting the delivery through
 *    would store a row the queue can only skip.
 *
 * Same harness as the repository tests: one global in-memory SQLite Capsule, the real model,
 * nothing mocked. The table is created only when absent and emptied per test, since the
 * Capsule is shared across every database-backed file in this process.
 */
function bootIdempotencyProfileSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors spatie's create_webhook_calls_table plus this package's extend_webhook_calls,
    // including the composite unique key this profile shares its invariant with. `id` is a
    // uuid because the model generates one through `HasUuids`; see the note in WebhookCallTest.
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
 * A delivery as it reaches the profile: the bridge has already run, so whatever it resolved
 * is on the request attribute.
 *
 * @param  array<string, mixed>|null  $meta  null means the bridge stashed nothing
 */
function idempotencyProfileRequest(?array $meta): Request
{
    $request = Request::create(
        'https://app.test/webhooks/payments',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"id":"evt_1"}',
    );

    if ($meta !== null) {
        $request->attributes->set(WebhookCall::REQUEST_META_ATTRIBUTE, $meta);
    }

    return $request;
}

/**
 * An already-stored delivery. Written through the model so the row this profile queries is
 * the same shape the machinery actually produces.
 */
function idempotencyProfileStoredCall(string $kind, ?string $externalId): WebhookCall
{
    return WebhookCall::query()->create([
        'name' => $kind,
        'gateway_id' => GatewayId::generate(),
        'external_id' => $externalId,
        'status' => WebhookCallStatus::Processed,
        'url' => 'https://app.test/webhooks/payments',
        'headers' => [],
        'payload' => ['id' => $externalId],
    ]);
}

beforeEach(function () {
    bootIdempotencyProfileSchema();

    $this->profile = new IdempotencyProfile;
});

it('lets a delivery it has never stored through', function () {
    expect($this->profile->shouldProcess(idempotencyProfileRequest([
        'gateway_id' => GatewayId::generate()->toString(),
        'kind' => 'stripe',
        'external_id' => 'evt_1',
    ])))->toBeTrue();
});

it('treats a second delivery of the same event as already seen', function () {
    // The whole purpose: providers redeliver until they get a 2xx, and every handler behind
    // this gate would otherwise be asked to apply the same event twice.
    idempotencyProfileStoredCall('stripe', 'evt_1');

    expect($this->profile->shouldProcess(idempotencyProfileRequest([
        'gateway_id' => GatewayId::generate()->toString(),
        'kind' => 'stripe',
        'external_id' => 'evt_1',
    ])))->toBeFalse();
});

it('does not treat another kind\'s identical event id as already seen', function () {
    // Event ids are only unique within a provider — Nuvei's synthesized
    // `transactionType:PPP_TransactionID` and Stripe's `evt_...` share a column. Dedup on the
    // id alone would silently drop one gateway's traffic as soon as another used the same
    // string.
    idempotencyProfileStoredCall('stripe', 'shared-1');

    expect($this->profile->shouldProcess(idempotencyProfileRequest([
        'gateway_id' => GatewayId::generate()->toString(),
        'kind' => 'nuvei',
        'external_id' => 'shared-1',
    ])))->toBeTrue();
});

it('lets a different event from a kind it has already stored through', function () {
    idempotencyProfileStoredCall('stripe', 'evt_1');

    expect($this->profile->shouldProcess(idempotencyProfileRequest([
        'gateway_id' => GatewayId::generate()->toString(),
        'kind' => 'stripe',
        'external_id' => 'evt_2',
    ])))->toBeTrue();
});

it('refuses a delivery the bridge could not attribute at all', function () {
    // No attribute means no gateway was identified. Storing it would produce exactly the row
    // the job can only mark skipped, so the refusal happens before anything is written.
    expect($this->profile->shouldProcess(idempotencyProfileRequest(null)))->toBeFalse();
});

it('refuses a delivery whose parser produced no idempotency key', function () {
    // A kind without an external id cannot be deduplicated: the next redelivery would look
    // just as new, and the unique index would not stop it either, because a null never
    // collides. Refusing is the only answer that keeps the guarantee.
    expect($this->profile->shouldProcess(idempotencyProfileRequest([
        'gateway_id' => GatewayId::generate()->toString(),
        'kind' => 'stripe',
    ])))->toBeFalse()
        ->and($this->profile->shouldProcess(idempotencyProfileRequest([
            'gateway_id' => GatewayId::generate()->toString(),
            'kind' => 'stripe',
            'external_id' => null,
        ])))->toBeFalse();
});

it('refuses a delivery with an idempotency key but no kind', function () {
    // The pair is what identifies an event; half of it cannot be matched against a row.
    expect($this->profile->shouldProcess(idempotencyProfileRequest([
        'gateway_id' => GatewayId::generate()->toString(),
        'external_id' => 'evt_1',
    ])))->toBeFalse();
});

it('is not shadowed by a stored row that carries no idempotency key', function () {
    // Rows written outside the verified path have a null external_id. SQL comparisons against
    // null never match, so such a row must not stand in the way of a genuine delivery of the
    // same kind — pinned because a `whereNull`-flavoured rewrite of that clause would.
    idempotencyProfileStoredCall('stripe', null);

    expect($this->profile->shouldProcess(idempotencyProfileRequest([
        'gateway_id' => GatewayId::generate()->toString(),
        'kind' => 'stripe',
        'external_id' => 'evt_1',
    ])))->toBeTrue();
});

it('treats a delivery as seen regardless of what became of the stored one', function () {
    // Dedup is about arrival, not outcome: a call that failed or was skipped has already been
    // accounted for, and re-admitting it would give the same event a second row and a second
    // job. Recovering from a failure is a requeue of THAT row, not a fresh delivery.
    foreach ([WebhookCallStatus::Pending, WebhookCallStatus::Skipped, WebhookCallStatus::Failed] as $status) {
        $externalId = 'evt_'.$status->value;
        idempotencyProfileStoredCall('stripe', $externalId)->update(['status' => $status]);

        expect($this->profile->shouldProcess(idempotencyProfileRequest([
            'gateway_id' => GatewayId::generate()->toString(),
            'kind' => 'stripe',
            'external_id' => $externalId,
        ])))->toBeFalse();
    }
});
