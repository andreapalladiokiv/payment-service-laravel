<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository;

/**
 * The first database-backed test in this package, and it exists for one behaviour that
 * nothing else could reach: whether `save()` MERGES transaction metadata or replaces it.
 *
 * That distinction is load-bearing and was wrong until recently. The comment on the
 * method promised "the auth response may carry metadata that the capture response
 * doesn't repeat, so the overwrite-on-transition semantics apply to the reference only",
 * while the code replaced the whole bag whenever a later response carried anything —
 * which ConnexPay's capture does, returning its incoming transaction code. Everything
 * the authorization recorded was dropped, including the rebilling anchor that now lives
 * there.
 *
 * Booted through Illuminate's Capsule rather than a package-testing harness on purpose:
 * one in-memory SQLite connection and the real repository, with no new dev dependency
 * and nothing mocked, so what is asserted is what Eloquent actually writes.
 */
function bootGatewayReferenceSchema(): void
{
    static $capsule = null;

    if ($capsule === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Mirrors create_gateway_references_table + add_metadata_to_gateway_references.
        // The gateway_id foreign key is left off: this exercises one repository, not the
        // schema's referential integrity.
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
}

beforeEach(fn () => bootGatewayReferenceSchema());

it('keeps metadata from an earlier write that a later one does not repeat', function () {
    $repo = new EloquentGatewayTransactionRepository;
    $gatewayId = GatewayId::generate();
    $piId = Uuid::uuid4()->toString();

    // Authorization: records its own reference plus the opening one.
    $repo->saveForPaymentIntent($gatewayId, $piId, 'auth_ref', ['opening_transaction_reference' => 'auth_ref']);

    // Capture: overwrites the reference and brings metadata of its own. Before the
    // merge this replaced the bag and the anchor vanished with it.
    $repo->saveForPaymentIntent($gatewayId, $piId, 'settle_ref', ['incoming_transaction_code' => 'ICT-1']);

    expect($repo->findForPaymentIntent($piId))->toBe('settle_ref')
        ->and($repo->findMetadataForPaymentIntent($piId))->toBe([
            'opening_transaction_reference' => 'auth_ref',
            'incoming_transaction_code' => 'ICT-1',
        ]);
});

it('lets a later write update a key it does repeat', function () {
    $repo = new EloquentGatewayTransactionRepository;
    $gatewayId = GatewayId::generate();
    $piId = Uuid::uuid4()->toString();

    $repo->saveForPaymentIntent($gatewayId, $piId, 'ref_1', ['incoming_transaction_code' => 'ICT-1']);
    $repo->saveForPaymentIntent($gatewayId, $piId, 'ref_2', ['incoming_transaction_code' => 'ICT-2']);

    // Merging must not mean pinning: the newer value for the same key wins.
    expect($repo->findMetadataForPaymentIntent($piId))->toBe(['incoming_transaction_code' => 'ICT-2']);
});

it('treats an empty metadata array as no signal rather than as erase', function () {
    $repo = new EloquentGatewayTransactionRepository;
    $gatewayId = GatewayId::generate();
    $piId = Uuid::uuid4()->toString();

    $repo->saveForPaymentIntent($gatewayId, $piId, 'auth_ref', ['opening_transaction_reference' => 'auth_ref']);
    // A gateway whose capture response carries nothing — Nuvei's, for one.
    $repo->saveForPaymentIntent($gatewayId, $piId, 'settle_ref', []);

    expect($repo->findMetadataForPaymentIntent($piId))->toBe(['opening_transaction_reference' => 'auth_ref'])
        ->and($repo->findForPaymentIntent($piId))->toBe('settle_ref');
});

it('answers with nothing for an intent it has never stored', function () {
    $repo = new EloquentGatewayTransactionRepository;

    expect($repo->findForPaymentIntent(Uuid::uuid4()->toString()))->toBeNull()
        ->and($repo->findMetadataForPaymentIntent(Uuid::uuid4()->toString()))->toBe([]);
});

it('keeps a refund reference apart from its payment intent', function () {
    $repo = new EloquentGatewayTransactionRepository;
    $gatewayId = GatewayId::generate();
    $piId = Uuid::uuid4()->toString();
    $refundId = Uuid::uuid4()->toString();

    $repo->saveForPaymentIntent($gatewayId, $piId, 'sale_ref', ['incoming_transaction_code' => 'ICT-1']);
    $repo->saveForRefund($gatewayId, $refundId, 'return_ref');

    // Different morph types, so a refund's reference cannot displace the sale's — the
    // rebilling anchor depends on that separation as much as on the merge.
    expect($repo->findForRefund($refundId))->toBe('return_ref')
        ->and($repo->findForPaymentIntent($piId))->toBe('sale_ref')
        ->and($repo->findMetadataForPaymentIntent($piId))->toBe(['incoming_transaction_code' => 'ICT-1']);
});
