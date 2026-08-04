<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
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
