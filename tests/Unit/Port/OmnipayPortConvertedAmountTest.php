<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\OmnipayCapturePort;
use Techork\PaymentService\Laravel\Port\OmnipayCreatePort;

it('carries the FX convertedAmount from a charge result into the CreateOutcome', function () {
    $converted = new Money(5712, new Currency('USD'));

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('charge')->once()->andReturn(
        AuthorizationResult::succeeded('ch_1')->withConvertedAmount($converted),
    );

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('saveForPaymentIntent')->once();

    $port = new OmnipayCreatePort($gateway, $txRepo, GatewayId::generate());

    $outcome = $port->create(new CreateRequest(
        paymentIntentId: PaymentIntentId::generate(),
        amount: new Money(5000, new Currency('EUR')),
        instrument: Mockery::mock(PaymentInstrument::class),
        captureMethod: CaptureMethod::Immediate,
        billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    ));

    expect($outcome->convertedAmount)->toBe($converted)
        ->and($outcome->challenge)->toBeNull();
});

it('leaves CreateOutcome convertedAmount null when the charge reports none', function () {
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('charge')->once()->andReturn(AuthorizationResult::succeeded('ch_2'));

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('saveForPaymentIntent')->once();

    $port = new OmnipayCreatePort($gateway, $txRepo, GatewayId::generate());

    $outcome = $port->create(new CreateRequest(
        paymentIntentId: PaymentIntentId::generate(),
        amount: new Money(5000, new Currency('USD')),
        instrument: Mockery::mock(PaymentInstrument::class),
        captureMethod: CaptureMethod::Immediate,
        billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    ));

    expect($outcome->convertedAmount)->toBeNull();
});

it('carries the FX convertedAmount from a capture result into the CaptureOutcome', function () {
    $converted = new Money(9140, new Currency('USD'));

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('capture')->once()->andReturn(
        GatewayResult::succeeded('cap_1')->withConvertedAmount($converted),
    );

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    // The port resolves the reference now — the gateway no longer reaches into our
    // storage for it.
    $txRepo->shouldReceive('findForPaymentIntent')->once()->andReturn('auth_ref');
    $txRepo->shouldReceive('saveForPaymentIntent')->once();

    $port = new OmnipayCapturePort($gateway, $txRepo, GatewayId::generate());

    $outcome = $port->capture(new CaptureRequest(
        paymentIntentId: PaymentIntentId::generate(),
        amount: new Money(8000, new Currency('EUR')),
    ));

    expect($outcome->convertedAmount)->toBe($converted);
});
