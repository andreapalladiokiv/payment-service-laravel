<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentTransactionIdResolver;

/**
 * The default `TransactionIdResolver`, uncovered until now, and the piece every webhook enters
 * through: a delivery arrives carrying only the gateway's own reference, and this class turns
 * it back into one of our aggregate ids. The recorders' idempotency gate is built on its
 * answer — {@see EloquentRefundRecorderTest} exercises that gate against a stub resolver, so
 * whether the real one can produce the answer at all was never checked anywhere.
 *
 * A wrong answer here is not a missing feature, it is a misattribution: resolve a sale
 * reference as a refund and a redelivery opens a refund against the wrong aggregate; resolve
 * across gateways and one tenant's webhook mutates another's payment. Both are the kind of
 * separation that only a real table can demonstrate, because it is the WHERE clause that
 * enforces it, and the references are written by
 * {@see EloquentGatewayTransactionRepository} — used here rather than hand-rolled inserts, so
 * the two halves of the round trip are pinned together.
 *
 * Same harness as the repository tests: one global in-memory SQLite Capsule, real classes,
 * nothing mocked, no new dev dependency. The table is created only when absent and emptied per
 * test, because the Capsule is shared across the database-backed files in this process.
 */
function bootTransactionIdResolverSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors create_gateway_references_table + add_metadata_to_gateway_references; the
    // gateway_id foreign key is left off, as this exercises the lookup rather than the
    // schema's referential integrity.
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
}

beforeEach(function () {
    bootTransactionIdResolverSchema();

    $this->resolver = new EloquentTransactionIdResolver;
    $this->transactions = new EloquentGatewayTransactionRepository;
    $this->gatewayId = GatewayId::generate();
    $this->paymentIntentId = Uuid::uuid4()->toString();
    $this->refundId = Uuid::uuid4()->toString();
});

it('turns a stored payment-intent reference back into the intent id', function () {
    $this->transactions->saveForPaymentIntent($this->gatewayId, $this->paymentIntentId, 'sale_ref');

    expect($this->resolver->resolvePaymentIntent($this->gatewayId, 'sale_ref'))->toBe($this->paymentIntentId);
});

it('turns a stored refund reference back into the refund id', function () {
    $this->transactions->saveForRefund($this->gatewayId, $this->refundId, 'return_ref');

    expect($this->resolver->resolveRefund($this->gatewayId, 'return_ref'))->toBe($this->refundId);
});

it('will not resolve a payment intent as a refund, or a refund as a payment intent', function () {
    // Several gateways number sales and returns from the same sequence, so the same string can
    // legitimately name both. Only the morph type keeps them apart, and getting it wrong is
    // worse than finding nothing: the refund recorder would read a payment intent's id as an
    // already-known refund and skip a genuine refund webhook as a duplicate.
    $sharedReference = 'TXN-1000';
    $this->transactions->saveForPaymentIntent($this->gatewayId, $this->paymentIntentId, $sharedReference);
    $this->transactions->saveForRefund($this->gatewayId, $this->refundId, $sharedReference);

    expect($this->resolver->resolvePaymentIntent($this->gatewayId, $sharedReference))->toBe($this->paymentIntentId)
        ->and($this->resolver->resolveRefund($this->gatewayId, $sharedReference))->toBe($this->refundId);
});

it('does not resolve another gateway\'s reference', function () {
    // Webhooks are received per gateway account and their references are only unique within
    // one. Unscoped, a delivery from one provider could resolve to an aggregate that belongs
    // to a payment taken somewhere else entirely — the multi-tenant leak the class documents
    // as the reason for the gateway_id filter.
    $otherGateway = GatewayId::generate();
    $this->transactions->saveForPaymentIntent($this->gatewayId, $this->paymentIntentId, 'sale_ref');
    $this->transactions->saveForRefund($this->gatewayId, $this->refundId, 'return_ref');

    expect($this->resolver->resolvePaymentIntent($otherGateway, 'sale_ref'))->toBeNull()
        ->and($this->resolver->resolveRefund($otherGateway, 'return_ref'))->toBeNull();
});

it('answers with nothing for a reference it holds no row for', function () {
    // The normal case on first delivery, and what the recorders read as "not a duplicate".
    expect($this->resolver->resolvePaymentIntent($this->gatewayId, 'never_seen'))->toBeNull()
        ->and($this->resolver->resolveRefund($this->gatewayId, 'never_seen'))->toBeNull();
});

it('follows the reference a capture moved, not the one the authorization opened with', function () {
    // Writes overwrite the reference on transition, so after a capture the row names the
    // settlement. Pinned as a pair with the transaction repository's merge rule: a webhook
    // quoting the retired authorization reference resolves to nothing, which is why recorders
    // must be driven by the reference the latest write stored.
    $this->transactions->saveForPaymentIntent($this->gatewayId, $this->paymentIntentId, 'auth_ref');
    $this->transactions->saveForPaymentIntent($this->gatewayId, $this->paymentIntentId, 'settle_ref');

    expect($this->resolver->resolvePaymentIntent($this->gatewayId, 'settle_ref'))->toBe($this->paymentIntentId)
        ->and($this->resolver->resolvePaymentIntent($this->gatewayId, 'auth_ref'))->toBeNull();
});

it('does not resolve an instrument reference as a transaction', function () {
    // The table's other tenants are instruments and virtual cards, whose references come from
    // the same provider and can collide with a transaction's as strings. A vault token
    // resolving to an aggregate id would let a tokenization webhook drive a payment stream.
    Capsule::table('gateway_references')->insert([
        'id' => Uuid::uuid4()->toString(),
        'gateway_id' => $this->gatewayId->toString(),
        'referenceable_type' => 'token',
        'referenceable_id' => Uuid::uuid4()->toString(),
        'reference' => 'AMBIGUOUS-1',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    expect($this->resolver->resolvePaymentIntent($this->gatewayId, 'AMBIGUOUS-1'))->toBeNull()
        ->and($this->resolver->resolveRefund($this->gatewayId, 'AMBIGUOUS-1'))->toBeNull();
});
