<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\EventParser;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\ParsedEvent;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier;
use Techork\PaymentService\Gateway\Webhook\Contract\StoredWebhookCall;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;
use Techork\PaymentService\Laravel\Webhook\Enum\WebhookCallStatus;
use Techork\PaymentService\Laravel\Webhook\Job\ProcessWebhookJob;
use Techork\PaymentService\Laravel\Webhook\Model\WebhookCall;

/**
 * The one queued job every delivery of every kind runs through, at zero coverage until now.
 * It carries three decisions that nothing else in the codebase can express, and each one is
 * only visible from the outside as a status on a row:
 *
 *  - a `gateway_id` that is null is TERMINAL, not a retry. The column is nullable and the
 *    job used to read it straight into `StoredWebhookCall`'s non-nullable `GatewayId`
 *    parameter — a TypeError on the path that exists for exactly that row. Marking it
 *    skipped is the fix, and "skipped rather than retried" is the part worth pinning:
 *    a call stored without a tenant can never be routed to one, so retrying would spin
 *    until the queue gave up and then record the wrong reason.
 *  - a `Delay` outcome is signalled by THROWING, because that is what makes Laravel's
 *    worker release the job under the supervisor's `backoff` config. Returning quietly
 *    instead would ack an event whose prerequisite has not arrived.
 *  - a call that is no longer pending is left completely alone, which is what makes a
 *    duplicate queue push harmless.
 *
 * The router is the REAL {@see WebhookRouter} over real registries, following
 * PaynetWebhookSubscriberTest: the outcome each test wants comes from a handler registered to
 * answer with it, not from a double that intercepts the call. That is a stronger statement
 * than a stub could make — it says the job hands over something the router can actually
 * route, which is where the interesting mismatches live: a kind that resolves no parser, a
 * payload no parser can read, a tenant that never becomes a value object.
 *
 * The `WebhookCall` is a real Eloquent row on an in-memory SQLite Capsule — the same harness
 * as the repository tests — because every assertion here is about a status that was written
 * and read back.
 */
function bootWebhookJobSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors spatie's create_webhook_calls_table plus this package's extend_webhook_calls.
    // `id` is a uuid because the model generates one through `HasUuids`; see the note in
    // WebhookCallTest.
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
 * A stored delivery in the state the queue picks it up in. The payload names its own event
 * type because that is what the parser below reads to pick a handler — the same indirection
 * a real gateway's parser performs.
 *
 * @param  array<string, mixed>  $attributes
 */
function webhookJobStoredCall(array $attributes = []): WebhookCall
{
    return WebhookCall::query()->create(array_merge([
        'name' => 'stripe',
        'gateway_id' => GatewayId::generate(),
        'external_id' => 'evt_'.bin2hex(random_bytes(5)),
        'status' => WebhookCallStatus::Pending,
        'url' => 'https://app.test/webhooks/payments',
        'headers' => [],
        'payload' => ['id' => 'evt_1', 'type' => 'thing.happened'],
    ], $attributes));
}

/**
 * A real {@see WebhookRouter} whose registered handler answers with `$outcome`.
 *
 * The credential repository refuses to answer: dispatching a stored call must not need
 * credentials at all — the tenant was decided at ingest and travels on the row — so a
 * repository that throws states that, and would surface a router which started re-resolving
 * it here instead of quietly working in a test and hitting the database in production.
 *
 * @param  ArrayObject<int, array<string, mixed>>  $parsed  every payload the parser saw
 * @param  ArrayObject<int, array{event: object, gateway_id: GatewayId}>  $handled
 */
function webhookJobRouter(
    HandlerOutcome $outcome,
    ArrayObject $parsed,
    ArrayObject $handled,
    string $kind = 'stripe',
): WebhookRouter {
    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;

    $verifiers->register(
        $kind,
        // Never reached on this path — verification happened at ingest — but the registry
        // holds the pair, so a verifier has to exist for the parser to be findable.
        new class implements SignatureVerifier
        {
            public function verify(ServerRequestInterface $request, GatewayCredential $gateway): bool
            {
                return false;
            }
        },
        new class($parsed) implements EventParser
        {
            /** @param ArrayObject<int, array<string, mixed>> $parsed */
            public function __construct(private readonly ArrayObject $parsed) {}

            public function parse(array $payload): ParsedEvent
            {
                $this->parsed[] = $payload;

                return new ParsedEvent(
                    type: is_string($payload['type'] ?? null) ? $payload['type'] : 'unreadable',
                    externalId: (string) ($payload['id'] ?? ''),
                    native: new ArrayObject($payload),
                );
            }
        },
    );

    $handlers->register($kind, 'thing.happened', new class($outcome, $handled) implements WebhookEventHandler
    {
        /** @param ArrayObject<int, array{event: object, gateway_id: GatewayId}> $handled */
        public function __construct(
            private readonly HandlerOutcome $outcome,
            private readonly ArrayObject $handled,
        ) {}

        public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
        {
            $this->handled[] = ['event' => $event, 'gateway_id' => $gatewayId];

            return $this->outcome;
        }
    });

    return new WebhookRouter(
        new readonly class implements GatewayCredentialRepository
        {
            public function findOrFail(GatewayId $gatewayId): GatewayCredential
            {
                throw new RuntimeException('Dispatching a stored call must not resolve credentials');
            }

            public function all(): iterable
            {
                throw new RuntimeException('Dispatching a stored call must not iterate tenants');
            }
        },
        $verifiers,
        $handlers,
    );
}

beforeEach(function () {
    bootWebhookJobSchema();

    $this->parsed = new ArrayObject;
    $this->handled = new ArrayObject;
});

it('marks a call the handler applied as processed', function () {
    $call = webhookJobStoredCall();

    new ProcessWebhookJob($call)->handle(webhookJobRouter(HandlerOutcome::Processed, $this->parsed, $this->handled));

    expect($call->fresh()->status)->toBe(WebhookCallStatus::Processed)
        ->and($call->fresh()->processed_at)->not->toBeNull()
        // Really routed: the parser read the row's payload and the handler ran.
        ->and($this->parsed)->toHaveCount(1)
        ->and($this->handled)->toHaveCount(1);
});

it('marks a call the handler had no work for as skipped, with the reason', function () {
    // Skipped is the normal answer to a redelivery or an event type nobody registered for,
    // and it has to be distinguishable from a failure: this row is acked, not retried.
    $call = webhookJobStoredCall();

    new ProcessWebhookJob($call)->handle(webhookJobRouter(HandlerOutcome::Skipped, $this->parsed, $this->handled));

    expect($call->fresh()->status)->toBe(WebhookCallStatus::Skipped)
        ->and($call->fresh()->exception)->toBe(['message' => 'Handler reported skip'])
        ->and($call->fresh()->processed_at)->not->toBeNull();
});

it('throws on a delay so the worker releases the job on its own backoff', function () {
    // Throwing is the signal, and the status staying Pending is what makes the retry pick
    // the call up again — a terminal status here would ack an event whose prerequisite has
    // not arrived yet, which is the one thing a Delay must never become.
    $call = webhookJobStoredCall();
    $router = webhookJobRouter(HandlerOutcome::Delay, $this->parsed, $this->handled);

    expect(fn () => new ProcessWebhookJob($call)->handle($router))
        ->toThrow(RuntimeException::class, 'Webhook handler requested retry');

    expect($call->fresh()->status)->toBe(WebhookCallStatus::Pending)
        ->and($call->fresh()->processed_at)->toBeNull();
});

it('skips a call stored without a gateway, terminally rather than as a retry', function () {
    // The defect this file exists for. `gateway_id` is nullable, and the job used to read it
    // into a non-nullable constructor parameter. The replacement decision is the assertion:
    // no throw — so the worker acks instead of releasing — a Skipped status, and a reason
    // that names the real cause. A Delay here would burn every attempt and then record
    // "max attempts exceeded" over a row whose actual problem was knowable at first sight.
    $call = webhookJobStoredCall(['gateway_id' => null]);

    new ProcessWebhookJob($call)->handle(webhookJobRouter(HandlerOutcome::Processed, $this->parsed, $this->handled));

    expect($call->fresh()->status)->toBe(WebhookCallStatus::Skipped)
        ->and($call->fresh()->exception)->toBe(['message' => 'Stored without a gateway id'])
        ->and($call->fresh()->processed_at)->not->toBeNull()
        // Never routed: there is no tenant to route to, and a handler that ran against a
        // guessed one would apply another merchant's event.
        ->and($this->parsed)->toHaveCount(0)
        ->and($this->handled)->toHaveCount(0);
});

it('leaves a call that is no longer pending completely alone', function () {
    // Makes a duplicate queue push harmless — the row is the lock. Without the guard a
    // second delivery of a processed call would re-enter the handler and, for a
    // non-idempotent one, replay a capture.
    foreach ([WebhookCallStatus::Processed, WebhookCallStatus::Skipped, WebhookCallStatus::Failed] as $status) {
        $call = webhookJobStoredCall(['status' => $status]);

        new ProcessWebhookJob($call)->handle(webhookJobRouter(HandlerOutcome::Processed, $this->parsed, $this->handled));

        expect($call->fresh()->status)->toBe($status)
            // Untouched, not re-stamped: the moment a terminal status was reached is
            // evidence, and this path must not overwrite it.
            ->and($call->fresh()->processed_at)->toBeNull();
    }

    expect($this->handled)->toHaveCount(0);
});

it('hands the router the kind, tenant and payload from the stored row', function () {
    // The job is the translation from storage into the framework-agnostic DTO. `name` being
    // the KIND rather than spatie's config entry name is what lets the router resolve a
    // parser at all — so the parser being reached under `nuvei` is itself an assertion, and
    // what it received is the rest of it.
    $gatewayId = GatewayId::generate();
    $call = webhookJobStoredCall([
        'name' => 'nuvei',
        'gateway_id' => $gatewayId,
        'payload' => ['id' => 'sale:1234', 'type' => 'thing.happened'],
    ]);

    new ProcessWebhookJob($call)
        ->handle(webhookJobRouter(HandlerOutcome::Processed, $this->parsed, $this->handled, 'nuvei'));

    expect($this->parsed[0])->toBe(['id' => 'sale:1234', 'type' => 'thing.happened'])
        // The tenant reaches the handler as the value object the contract declares, which is
        // the conversion the nullable column used to break.
        ->and($this->handled[0]['gateway_id'])->toBeInstanceOf(GatewayId::class)
        ->and($this->handled[0]['gateway_id']->toString())->toBe($gatewayId->toString())
        ->and($call->fresh()->status)->toBe(WebhookCallStatus::Processed);
});

it('acks a stored call of a kind nothing is registered for', function () {
    // Reachable after a gateway package is removed while its deliveries are still queued.
    // The router answers Skipped for a kind with no parser, and the job has to treat that as
    // terminal: nothing will ever appear to handle it, so retrying is time spent to reach the
    // same answer.
    $call = webhookJobStoredCall(['name' => 'uninstalled-gateway']);

    new ProcessWebhookJob($call)->handle(webhookJobRouter(HandlerOutcome::Processed, $this->parsed, $this->handled));

    expect($call->fresh()->status)->toBe(WebhookCallStatus::Skipped)
        ->and($call->fresh()->exception)->toBe(['message' => 'Handler reported skip'])
        ->and($this->parsed)->toHaveCount(0);
});

it('routes an empty payload rather than refusing a body-less delivery', function () {
    // The column is nullable and some providers ping with nothing in the body. The parser
    // gets an empty array and decides — here it yields a type nobody handles, so the row is
    // acked as skipped. What matters is that the null never reaches the DTO.
    $call = webhookJobStoredCall(['payload' => null]);

    new ProcessWebhookJob($call)->handle(webhookJobRouter(HandlerOutcome::Processed, $this->parsed, $this->handled));

    expect($this->parsed[0])->toBe([])
        ->and($this->handled)->toHaveCount(0)
        ->and($call->fresh()->status)->toBe(WebhookCallStatus::Skipped);
});

it('carries the parsed event, not the stored row, into the handler', function () {
    // The handler contract takes the gateway-native object its own parser produced; the row's
    // array is that parser's input and must not be what arrives. Pinned because both are "the
    // payload" in conversation and only one of them is the contract.
    $call = webhookJobStoredCall();

    new ProcessWebhookJob($call)->handle(webhookJobRouter(HandlerOutcome::Processed, $this->parsed, $this->handled));

    expect($this->handled[0]['event'])->toBeInstanceOf(ArrayObject::class)
        ->and($this->handled[0]['event'])->not->toBeInstanceOf(StoredWebhookCall::class);
});

it('records the diagnosis on the row when the queue gives up', function () {
    // `failed()` runs after the last attempt, and this row is the only place the reason
    // survives — the job and its exception are gone by then.
    $call = webhookJobStoredCall();

    new ProcessWebhookJob($call)->failed(new RuntimeException('Webhook handler requested retry', 7));

    expect($call->fresh()->status)->toBe(WebhookCallStatus::Failed)
        ->and($call->fresh()->exception['code'])->toBe(7)
        ->and($call->fresh()->exception['message'])->toBe('Webhook handler requested retry')
        ->and($call->fresh()->exception['trace'])->toBeString();
});

it('leaves the row untouched when it is failed without an exception', function () {
    // Laravel allows a null there (a job released by a timeout, for instance). Writing a
    // Failed status with no diagnosis would retire a call the operator cannot act on, so the
    // row keeps its pending state and stays visible to the pending scope.
    $call = webhookJobStoredCall();

    new ProcessWebhookJob($call)->failed(null);

    expect($call->fresh()->status)->toBe(WebhookCallStatus::Pending)
        ->and($call->fresh()->exception)->toBeNull()
        ->and($call->fresh()->processed_at)->toBeNull();
});
