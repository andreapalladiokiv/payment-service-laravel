<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Laravel\Port\OmnipayCreatePort;

function createRequestForReturnUrl(CaptureMethod $captureMethod): CreateRequest
{
    return new CreateRequest(
        paymentIntentId: PaymentIntentId::generate(),
        amount: new Money(5000, new Currency('USD')),
        instrument: Mockery::mock(PaymentInstrument::class),
        captureMethod: $captureMethod,
        billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );
}

/**
 * The address rides the port rather than {@see CreateRequest}, so it never passes through
 * the aggregate — which has no use for it — and no host has to add a method to its create
 * command to carry a value the domain never reads. It reaches the gateway all the same.
 */
it('hands the gateway the address the cardholder comes back to', function (CaptureMethod $captureMethod, string $verb) {
    $seen = null;

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive($verb)->once()->andReturnUsing(function (...$args) use (&$seen) {
        $seen = $args;

        return AuthorizationResult::succeeded('ref_1');
    });

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('saveForPaymentIntent')->once();

    $port = new OmnipayCreatePort($gateway, $txRepo, GatewayId::generate(), 'https://merchant.example/checkout/back');

    $port->create(createRequestForReturnUrl($captureMethod));

    expect(end($seen))->toBe('https://merchant.example/checkout/back');
})->with([
    'authorize' => [CaptureMethod::Manual, 'authorize'],
    'charge' => [CaptureMethod::Immediate, 'charge'],
]);

it('names no address when the caller has nowhere to bring anyone back to', function () {
    $seen = null;

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('authorize')->once()->andReturnUsing(function (...$args) use (&$seen) {
        $seen = $args;

        return AuthorizationResult::succeeded('ref_2');
    });

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('saveForPaymentIntent')->once();

    $port = new OmnipayCreatePort($gateway, $txRepo, GatewayId::generate());

    $port->create(createRequestForReturnUrl(CaptureMethod::Manual));

    expect(end($seen))->toBeNull();
});
