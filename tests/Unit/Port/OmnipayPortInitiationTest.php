<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\OmnipayCreatePort;

/**
 * `CreateRequest` has carried the initiation since CIT/MIT was modelled, but this
 * port used to pass six of the arguments the gateway accepts and drop it, so no
 * acquirer ever learned that a subscription renewal was merchant-initiated.
 * These pin the forwarding on both branches, because the port picks the method by
 * capture method and the argument had to be added to each call separately.
 */
function initiationCreateRequest(
    CaptureMethod $captureMethod,
    PaymentInitiation $initiation,
): CreateRequest {
    return new CreateRequest(
        paymentIntentId: PaymentIntentId::generate(),
        amount: new Money(5000, new Currency('USD')),
        instrument: Mockery::mock(PaymentInstrument::class),
        captureMethod: $captureMethod,
        billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
        initiation: $initiation,
    );
}

function initiationPort(PaymentGatewayInterface $gateway): OmnipayCreatePort
{
    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('saveForPaymentIntent')->zeroOrMoreTimes();

    return new OmnipayCreatePort($gateway, $txRepo, GatewayId::generate());
}

it('forwards the initiation to charge on the immediate-capture branch', function (PaymentInitiation $initiation) {
    $seen = null;

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('charge')->once()
        ->andReturnUsing(function (...$args) use (&$seen) {
            // Positional, because that is how the port calls it and a named
            // argument landing in the wrong slot is exactly what this catches.
            $seen = $args[8] ?? null;

            return AuthorizationResult::succeeded('ch_1');
        });

    initiationPort($gateway)->create(initiationCreateRequest(CaptureMethod::Immediate, $initiation));

    expect($seen)->toBe($initiation);
})->with([
    PaymentInitiation::CardholderInitiated,
    PaymentInitiation::MerchantRecurring,
    PaymentInitiation::MerchantUnscheduled,
]);

it('forwards the initiation to authorize on the authorize-then-capture branch', function (PaymentInitiation $initiation) {
    $seen = null;

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('authorize')->once()
        ->andReturnUsing(function (...$args) use (&$seen) {
            $seen = $args[8] ?? null;

            return AuthorizationResult::succeeded('auth_1');
        });

    initiationPort($gateway)->create(initiationCreateRequest(CaptureMethod::Manual, $initiation));

    expect($seen)->toBe($initiation);
})->with([
    PaymentInitiation::CardholderInitiated,
    PaymentInitiation::MerchantRecurring,
    PaymentInitiation::MerchantUnscheduled,
]);
