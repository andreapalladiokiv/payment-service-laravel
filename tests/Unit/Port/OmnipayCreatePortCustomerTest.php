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
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Laravel\Port\OmnipayCapturePort;
use Techork\PaymentService\Laravel\Port\OmnipayCreatePort;

function customerCreateRequest(CaptureMethod $captureMethod = CaptureMethod::Manual): CreateRequest
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
 * Whose payment this is rides the port, not `CreateRequest` — the same route the gateway id
 * takes. The aggregate would carry it without ever reading it, and putting it on
 * `CreatePaymentIntentCommand` would break every host implementation for a value none of them
 * would use.
 */
it('tells the gateway whose payment this is', function (CaptureMethod $captureMethod, string $verb) {
    $customerId = '0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff';
    $seen = null;

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive($verb)->once()->andReturnUsing(function (...$args) use (&$seen) {
        $seen = $args;

        return AuthorizationResult::succeeded('ref_1');
    });

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturnNull();
    $txRepo->shouldReceive('saveForPaymentIntent')->once();

    new OmnipayCreatePort($gateway, $txRepo, GatewayId::generate(), $customerId)
        ->create(customerCreateRequest($captureMethod));

    expect($seen)->toContain($customerId);
})->with([
    'authorize' => [CaptureMethod::Manual, 'authorize'],
    'charge' => [CaptureMethod::Immediate, 'charge'],
]);

it('names no customer when the caller did not', function () {
    $seen = null;

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('authorize')->once()->andReturnUsing(function (...$args) use (&$seen) {
        $seen = $args;

        return AuthorizationResult::succeeded('ref_2');
    });

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturnNull();
    $txRepo->shouldReceive('saveForPaymentIntent')->once();

    new OmnipayCreatePort($gateway, $txRepo, GatewayId::generate())->create(customerCreateRequest());

    expect(end($seen))->toBeNull();
});

/**
 * And on the capture, for the acquirer that records the customer on both. ConnexPay documents a
 * Capture's `OrderNumber` as overwriting the Auth's and says nothing about `CustomerID`; if it
 * behaves the same, a capture that names no customer blanks what the authorization recorded.
 */
it('tells the gateway whose payment a capture belongs to', function () {
    $customerId = '0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff';
    $seen = null;

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('capture')->once()->andReturnUsing(function (...$args) use (&$seen) {
        $seen = $args;

        return GatewayResult::succeeded('cap_1');
    });

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('txn_1');
    $txRepo->shouldReceive('saveForPaymentIntent')->once();

    new OmnipayCapturePort($gateway, $txRepo, GatewayId::generate(), $customerId)
        ->capture(new CaptureRequest(
            PaymentIntentId::generate(),
            new Money(5000, new Currency('USD')),
            new Money(5000, new Currency('USD')),
            Mockery::mock(PaymentInstrument::class),
        ));

    expect($seen)->toContain($customerId);
});

/**
 * The other half of "optional", and the branch F6's closure rests on: a capture for which nobody
 * named a customer must pass nothing rather than invent something. ConnexPay then omits
 * `CustomerID` entirely, so there is no value for an overwrite to lose.
 */
it('names no customer on a capture when the caller did not', function () {
    $seen = null;

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('capture')->once()->andReturnUsing(function (...$args) use (&$seen) {
        $seen = $args;

        return GatewayResult::succeeded('cap_2');
    });

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('txn_1');
    $txRepo->shouldReceive('saveForPaymentIntent')->once();

    new OmnipayCapturePort($gateway, $txRepo, GatewayId::generate())
        ->capture(new CaptureRequest(
            PaymentIntentId::generate(),
            new Money(5000, new Currency('USD')),
            new Money(5000, new Currency('USD')),
            Mockery::mock(PaymentInstrument::class),
        ));

    expect(end($seen))->toBeNull();
});
