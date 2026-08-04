<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Laravel\Port\GatewayReferenceMetadata;
use Techork\PaymentService\Laravel\Port\OmnipayCreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CancelRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\OmnipayCancelPort;
use Techork\PaymentService\Laravel\Port\OmnipayCapturePort;
use Techork\PaymentService\Laravel\Port\OmnipayRefundPort;

/**
 * Reading the acquirer's reference and writing it now live in the same layer. The
 * gateway used to read it itself while the ports wrote it, which split one identity's
 * lifecycle in two and — worse — let the same missing row mean different things per
 * operation: cancel returned a failed result, capture and refund threw.
 */
function referenceRepo(?string $reference): GatewayTransactionRepository
{
    $repo = Mockery::mock(GatewayTransactionRepository::class);
    $repo->shouldReceive('findForPaymentIntent')->andReturn($reference);
    $repo->shouldReceive('saveForPaymentIntent')->zeroOrMoreTimes();
    $repo->shouldReceive('saveForRefund')->zeroOrMoreTimes();

    return $repo;
}

function unreachableGateway(): PaymentGatewayInterface
{
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('capture')->never();
    $gateway->shouldReceive('cancel')->never();
    $gateway->shouldReceive('refund')->never();

    return $gateway;
}

it('hands the resolved reference to the gateway, which no longer looks it up', function () {
    $seen = null;

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('capture')->once()->andReturnUsing(function (...$args) use (&$seen) {
        $seen = $args[1];

        return GatewayResult::succeeded('cap_1');
    });

    (new OmnipayCapturePort($gateway, referenceRepo('auth_ref'), GatewayId::generate()))
        ->capture(new CaptureRequest(PaymentIntentId::generate(), new Money(100, new Currency('USD'))));

    expect($seen)->toBe('auth_ref');
});

it('refuses a capture with no recorded reference instead of asking the acquirer', function () {
    $port = new OmnipayCapturePort(unreachableGateway(), referenceRepo(null), GatewayId::generate());

    expect(fn () => $port->capture(new CaptureRequest(PaymentIntentId::generate(), new Money(100, new Currency('USD')))))
        ->toThrow(RuntimeException::class, 'No gateway transaction reference recorded');
});

it('refuses a refund with no recorded reference instead of recording RefundFailed', function () {
    $port = new OmnipayRefundPort(unreachableGateway(), referenceRepo(null), GatewayId::generate());

    // Not GatewayDeclinedException: that is what the aggregate turns into RefundFailed,
    // and nobody declined anything here.
    expect(fn () => $port->refund(new RefundRequest(
        paymentIntentId: PaymentIntentId::generate(),
        refundId: RefundId::generate(),
        amount: new Money(100, new Currency('USD')),
    )))
        ->toThrow(RuntimeException::class, 'No gateway transaction reference recorded')
        ->not->toThrow(GatewayDeclinedException::class);
});

it('stops reporting a missing reference as an issuer refusing a cancellation', function () {
    // The behaviour this refactor changes. Before, the gateway answered a missing row
    // with GatewayResult::failed(), the port turned that into GatewayDeclinedException,
    // and the aggregate recorded a failure — telling operators an issuer said no to a
    // cancellation it never received.
    $port = new OmnipayCancelPort(unreachableGateway(), referenceRepo(null), GatewayId::generate());

    $thrown = null;

    try {
        $port->cancel(new CancelRequest(PaymentIntentId::generate()));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown)->not->toBeInstanceOf(GatewayDeclinedException::class)
        ->and($thrown->getMessage())->toContain('No gateway transaction reference recorded');
});

// ──────────────────────────────────────────────
//  the opening reference is recorded, so a capture cannot bury it
//
//  Only half of this is testable here. That the ports WRITE the key is asserted
//  below; that the repository MERGES it rather than replacing the bag has no test,
//  because the tree has no database harness and the Eloquent repositories are
//  untested altogether. The merge is where a regression would hide.
// ──────────────────────────────────────────────

function metadataCapturingRepo(?array &$seen): GatewayTransactionRepository
{
    $repo = Mockery::mock(GatewayTransactionRepository::class);
    $repo->shouldReceive('saveForPaymentIntent')->once()
        ->andReturnUsing(function (...$args) use (&$seen) {
            $seen = $args[3] ?? null;
        });

    return $repo;
}

it('records the opening reference beside the metadata the gateway returned', function () {
    $seen = null;

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('authorize')->once()->andReturn(
        AuthorizationResult::succeeded('auth_ref')->withMetadata(['incoming_transaction_code' => 'itc_1']),
    );

    (new OmnipayCreatePort($gateway, metadataCapturingRepo($seen), GatewayId::generate()))
        ->create(new CreateRequest(
            paymentIntentId: PaymentIntentId::generate(),
            amount: new Money(100, new Currency('USD')),
            instrument: Mockery::mock(PaymentInstrument::class),
            captureMethod: CaptureMethod::Manual,
            billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
        ));

    // Beside, not instead of: the gateway's own metadata survives, and the opening
    // reference joins it under a key `reference` will not overwrite on capture.
    expect($seen[GatewayReferenceMetadata::OPENING_REFERENCE])->toBe('auth_ref')
        ->and($seen['incoming_transaction_code'])->toBe('itc_1');
});
