<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Domain\Dispute\DisputeAggregateRepositoryInterface;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\ReasonCategory;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentImported;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeResolution;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\UnmatchedDispute;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateMessageRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateSnapshotRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\DisputeAggregateRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\PaymentIntentAggregateRepository;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentDisputeRecorder;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentTransactionIdResolver;
use Techork\PaymentService\Tests\Support\EventStreamDatabase;

/**
 * A0: the recorder that turns an observation into a case, across the real repositories.
 *
 * ## Why this drives real aggregates rather than a fake repository
 *
 * The refund recorder's suite hands its recorder a hand-written fake, which is right for what that
 * file asserts — one call, one event. This file asserts something a fake cannot show: that a provider
 * reference survives a round trip through `gateway_references` and **comes back as the same dispute**
 * on the next delivery, which is the whole reason that row exists. So the wiring is real — SQLite,
 * the production serialiser, `EloquentTransactionIdResolver` reading the row it just wrote — and the
 * assertions read the aggregate the recorder persisted rather than a recorder of our own configuring.
 *
 * ## The two tables, in one connection
 *
 * `stored_events` and `aggregate_snapshots` come from {@see EventStreamDatabase}'s own schema, and
 * `gateway_references` from the migration the reference repository's suite mirrors. They share one
 * global Capsule because the resolver reaches its table through Eloquent statically while the message
 * repository takes a connection, and two in-memory databases would put the aggregate in one and its
 * reference in the other. The table creation is guarded and the rows are emptied per test, since
 * several files in this process share the global capsule — the idiom
 * {@see EloquentGatewayTransactionRepository} 's suite established.
 */
function bootDisputeRecorderSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    if (! Capsule::schema()->hasTable('stored_events')) {
        Capsule::schema()->create('stored_events', function ($table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->uuid('aggregate_root_id')->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->json('payload');
            $table->index(['aggregate_root_id', 'version']);
        });
    }

    if (! Capsule::schema()->hasTable('aggregate_snapshots')) {
        Capsule::schema()->create('aggregate_snapshots', function ($table) {
            $table->uuid('aggregate_root_id')->primary();
            $table->unsignedInteger('aggregate_root_version');
            $table->json('state');
            $table->timestamp('created_at')->nullable();
        });
    }

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

    foreach (['stored_events', 'aggregate_snapshots', 'gateway_references'] as $table) {
        Capsule::table($table)->delete();
    }
}

/**
 * The recorder and both repositories it drives, wired the way the service provider wires them.
 *
 * @return array{
 *     0: EloquentDisputeRecorder,
 *     1: DisputeAggregateRepositoryInterface,
 *     2: PaymentIntentAggregateRepository,
 *     3: EloquentTransactionIdResolver,
 *     4: GatewayId,
 * }
 */
function disputeRecorderWiring(): array
{
    bootDisputeRecorderSchema();

    $connection = Capsule::connection();

    // The same serialiser chain the bridge binds, so an event that cannot round-trip fails here
    // rather than in production.
    $messages = new IlluminateMessageRepository($connection, 'stored_events', EventStreamDatabase::messageSerializer());
    $snapshots = new IlluminateSnapshotRepository($connection);

    $disputes = new DisputeAggregateRepository($messages, $snapshots);
    $intents = new PaymentIntentAggregateRepository($messages, $snapshots);
    $references = new EloquentGatewayTransactionRepository;
    $resolver = new EloquentTransactionIdResolver;

    return [
        new EloquentDisputeRecorder($intents, $disputes, $references, $resolver),
        $disputes,
        $intents,
        $resolver,
        GatewayId::generate(),
    ];
}

/**
 * A payment of ours, so the recorder sees one that has already been observed.
 *
 * `PaymentIntentImported` rather than a charged intent on purpose: the recorder's question is only
 * whether the payment exists in the stream (`aggregateRootVersion() === 0` is its `NotFound` test),
 * and what the payment *is* plays no part in a dispute.
 */
function disputeRecorderObservedPayment(PaymentIntentAggregateRepository $intents, string $paymentIntentId): void
{
    $intents->persistEvents(PaymentIntentId::fromString($paymentIntentId), 1, new PaymentIntentImported(
        new Money(12000, new Currency('USD')),
        PaymentIntentStatus::Charged,
        HostedPayment::unknown(),
        CaptureMethod::Immediate,
        laravelSuiteCustomer(),
        new MerchantDescriptor('ACME STORE'),
        'A payment a case was raised against',
    ));
}

function disputeRecorderAmount(int $minor = 12000): Money
{
    return new Money($minor, new Currency('USD'));
}

/**
 * A snapshot in the Nuvei shape: the provider's own code beside the domain spelling, the brand the
 * adapter read off the reason code's namespace, and none of the provider's bookkeeping.
 *
 * `stageCode` carries `Chargeback.Type` — the provider's word, and the code that tells a second
 * visit to a stage from a redelivery — while `stage` carries the spelling the adapter read it as.
 * `statusCode` is left null here: what Nuvei's `DisputeUnifiedStatusCode` actually spells is on the
 * UNCONFIRMED list, and inventing a value for it in a fixture would test our own guess.
 *
 * @param  array<string, mixed>  $overrides
 */
function disputeRecorderSnapshot(array $overrides = []): DisputeSnapshot
{
    return new DisputeSnapshot(...[
        'gatewayDisputeRef' => 'CB-1001',
        'cardBrand' => CardBrand::Visa,
        'reasonCode' => '10.4',
        'providerEventKey' => 'nuvei:evt-1',
        'observedAt' => new DateTimeImmutable('2026-09-14 10:00:00'),
        'stageCode' => 'Chargeback',
        'stage' => 'chargeback',
        'status' => 'needs_response',
        'disputedAmount' => disputeRecorderAmount(),
        'responseDueAt' => new DateTimeImmutable('2026-09-20 00:00:00'),
        ...$overrides,
    ]);
}

beforeEach(function () {
    $this->paymentIntentId = '01961f5a-0000-7000-8000-0000000000e1';
});

it('opens a case on the first observation, and keeps the reference that makes the next one a move', function () {
    [$recorder, $disputes, $intents, $resolver, $gatewayId] = disputeRecorderWiring();
    disputeRecorderObservedPayment($intents, $this->paymentIntentId);

    $outcome = $recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, disputeRecorderSnapshot());

    expect($outcome)->toBe(RecorderOutcome::Applied);

    $disputeId = $resolver->resolveDispute($gatewayId, 'CB-1001');

    expect($disputeId)->not->toBeNull();

    $dispute = $disputes->retrieve(DisputeId::fromString((string) $disputeId));

    expect($dispute->aggregateRootVersion())->toBe(1)
        ->and($dispute->paymentIntentId()->toString())->toBe($this->paymentIntentId)
        ->and($dispute->stage())->toBe(DisputeStage::Chargeback)
        ->and($dispute->status())->toBe(DisputeStatus::NeedsResponse)
        ->and($dispute->disputedAmount())->toEqual(disputeRecorderAmount())
        ->and($dispute->deadlineAt()?->format('Y-m-d'))->toBe('2026-09-20')
        // The brand is the adapter's, and the category is the domain's own reading of the code it
        // came with — `10.4` is Visa's, and it is categorised rather than left unknown.
        ->and($dispute->reason()->cardBrand)->toBe(CardBrand::Visa)
        ->and($dispute->reason()->category)->toBe(ReasonCategory::Fraud);

    // One row, naming our aggregate — not the payment. A dispute is addressed by our id on the port
    // side and by the provider's reference on the delivery side, and this row is the only place both
    // are held.
    expect(Capsule::table('gateway_references')
        ->where('referenceable_type', EloquentGatewayTransactionRepository::TYPE_DISPUTE)
        ->value('referenceable_id'))->toBe($disputeId);
});

it('moves the case a later delivery is about, instead of opening a second one for it', function () {
    [$recorder, $disputes, $intents, $resolver, $gatewayId] = disputeRecorderWiring();
    disputeRecorderObservedPayment($intents, $this->paymentIntentId);

    $recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, disputeRecorderSnapshot());
    $firstId = $resolver->resolveDispute($gatewayId, 'CB-1001');

    $outcome = $recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, disputeRecorderSnapshot([
        'providerEventKey' => 'nuvei:evt-2',
        'status' => 'lost',
    ]));

    expect($outcome)->toBe(RecorderOutcome::Applied)
        // The same aggregate: the reference resolved, so nothing was minted. A second aggregate here
        // would mean one dispute counted twice, which is the failure the reference row exists to
        // prevent.
        ->and($resolver->resolveDispute($gatewayId, 'CB-1001'))->toBe($firstId);

    $dispute = $disputes->retrieve(DisputeId::fromString((string) $firstId));

    expect($dispute->aggregateRootVersion())->toBe(2)
        ->and($dispute->status())->toBe(DisputeStatus::Lost)
        ->and($dispute->stage())->toBe(DisputeStage::Chargeback);
});

it('records nothing when a delivery it has already applied arrives again', function () {
    [$recorder, $disputes, $intents, $resolver, $gatewayId] = disputeRecorderWiring();
    disputeRecorderObservedPayment($intents, $this->paymentIntentId);

    $snapshot = disputeRecorderSnapshot();

    $recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, $snapshot);
    $disputeId = (string) $resolver->resolveDispute($gatewayId, 'CB-1001');

    // The same bytes a second time. `Applied`, not `Skipped`: a redelivery is not a failure, and the
    // version asserted below is what makes that claim mean anything — the recorder must answer that
    // it did its work while the aggregate records nothing. Asserting only the version would read the
    // same if a refusal had been swallowed instead.
    expect($recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, $snapshot))
        ->toBe(RecorderOutcome::Applied);

    // One event, not two. The delivery states three facts at once — the stage, the status and the
    // deadline — and a guard that remembered only the key would still have to remember which of them
    // it had already recorded; this asserts the observable end of that, which is that a redelivery is
    // silent rather than a second `DisputeOpened`.
    expect($disputes->retrieve(DisputeId::fromString($disputeId))->aggregateRootVersion())->toBe(1);
});

it('answers NotFound for a payment it has not seen, and writes nothing', function () {
    [$recorder, , , $resolver, $gatewayId] = disputeRecorderWiring();

    // No event persisted for this payment: a case can be polled before the sale's own webhook lands,
    // which is arrival order rather than an error, so the caller retries.
    $outcome = $recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, disputeRecorderSnapshot());

    expect($outcome)->toBe(RecorderOutcome::NotFound)
        ->and($resolver->resolveDispute($gatewayId, 'CB-1001'))->toBeNull()
        ->and(Capsule::table('stored_events')->count())->toBe(0);
});

it('records a resolution against the case the reference names', function () {
    [$recorder, $disputes, $intents, $resolver, $gatewayId] = disputeRecorderWiring();
    disputeRecorderObservedPayment($intents, $this->paymentIntentId);

    $recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, disputeRecorderSnapshot());
    $disputeId = (string) $resolver->resolveDispute($gatewayId, 'CB-1001');

    $outcome = $recorder->onDisputeResolved($gatewayId, 'CB-1001', new DisputeResolution(
        statusCode: 'FC-CLSD-MF',
        providerEventKey: 'nuvei:evt-3',
        observedAt: new DateTimeImmutable('2026-09-25 10:00:00'),
        status: 'won',
    ));

    expect($outcome)->toBe(RecorderOutcome::Applied)
        ->and($disputes->retrieve(DisputeId::fromString($disputeId))->status())->toBe(DisputeStatus::Won);
});

it('answers NotFound for a resolution of a case it never observed', function () {
    [$recorder, , , , $gatewayId] = disputeRecorderWiring();

    $outcome = $recorder->onDisputeResolved($gatewayId, 'CB-never-seen', new DisputeResolution(
        statusCode: 'FC-CLSD-CHF',
        providerEventKey: 'nuvei:evt-4',
        observedAt: new DateTimeImmutable('2026-09-25 10:00:00'),
        status: 'lost',
    ));

    expect($outcome)->toBe(RecorderOutcome::NotFound);
});

it('skips a transition the aggregate refuses rather than throwing at the caller', function () {
    [$recorder, $disputes, $intents, $resolver, $gatewayId] = disputeRecorderWiring();
    disputeRecorderObservedPayment($intents, $this->paymentIntentId);

    // Decided at creation: nothing may walk it back to needing an answer, and the aggregate refuses
    // loudly. The provider did whatever it did regardless, so retrying cannot change our answer —
    // which is what makes this `Skipped` and not a `Delay`.
    $recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, disputeRecorderSnapshot([
        'status' => 'won',
    ]));

    $outcome = $recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, disputeRecorderSnapshot([
        'providerEventKey' => 'nuvei:evt-5',
        'status' => 'needs_response',
    ]));

    expect($outcome)->toBe(RecorderOutcome::Skipped);

    $disputeId = (string) $resolver->resolveDispute($gatewayId, 'CB-1001');

    expect($disputes->retrieve(DisputeId::fromString($disputeId))->status())->toBe(DisputeStatus::Won);
});

it('refuses a case that cannot be placed rather than choosing a stage for it', function () {
    // The refusal is the point: an unplaced case would be given another phase's deadline and
    // evidence set, and `DisputeStage` has no default for exactly this reason. The adapter that read
    // the payload is the only place that knows what the provider meant by its code, so a delivery
    // that reaches here without one is a mapping gap that has to be seen.
    [$recorder, , $intents, , $gatewayId] = disputeRecorderWiring();
    disputeRecorderObservedPayment($intents, $this->paymentIntentId);

    expect(fn () => $recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, disputeRecorderSnapshot([
        'stage' => null,
        'stageCode' => null,
    ])))->toThrow(InvalidArgumentException::class);
});

it('refuses a raw stage code with no spelling beside it, instead of reading the code as a stage', function () {
    // The ConnexPay shape, which is where this case is real rather than hypothetical: the CMS
    // states `CaseType` 1 and no stage, because `CaseType` is the *cycle position* — a second
    // chargeback is `CaseType` 2 in the same stage. A recorder that read `'1'` as a stage would file
    // the case under whichever phase it guessed, and one that skipped the axis would drop every
    // stage change ConnexPay reports. It refuses instead, naming the field the adapter must fill.
    [$recorder, , $intents, $resolver, $gatewayId] = disputeRecorderWiring();
    disputeRecorderObservedPayment($intents, $this->paymentIntentId);

    expect(fn () => $recorder->onDisputeObserved($gatewayId, $this->paymentIntentId, disputeRecorderSnapshot([
        'stageCode' => '1',
        'stage' => null,
    ])))->toThrow(InvalidArgumentException::class);

    // And nothing was written on the way out: no aggregate for the case, no reference row, so the
    // delivery is retried rather than half-recorded.
    expect($resolver->resolveDispute($gatewayId, 'CB-1001'))->toBeNull()
        ->and(Capsule::table('stored_events')->count())->toBe(1);
});

it('skips an unmatched case, which no aggregate can hold', function () {
    [$recorder, , , , $gatewayId] = disputeRecorderWiring();

    $outcome = $recorder->onUnmatchedDispute($gatewayId, new UnmatchedDispute(
        gatewayDisputeRef: 'CB-orphan',
        cardBrand: CardBrand::Visa,
        reasonCode: '10.4',
        providerEventKey: 'connexpay:hash-1',
        observedAt: new DateTimeImmutable('2026-09-14 10:00:00'),
        stageCode: '1',
    ));

    // Not a throw and not a stored row: the case is real and against nobody of ours, and an
    // aggregate cannot exist without a payment. Surfacing it is the read model's job.
    expect($outcome)->toBe(RecorderOutcome::Skipped)
        ->and(Capsule::table('stored_events')->count())->toBe(0);
});
