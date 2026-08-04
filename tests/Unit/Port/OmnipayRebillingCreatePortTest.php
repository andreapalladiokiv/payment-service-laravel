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
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\OmnipayRebillingCreatePort;

/**
 * The genesis is held by the PORT, not carried in the request: the caller builds this
 * port per payment (`new OmnipayRebillingCreatePort(...)` at the call site, never
 * resolved from a container), and it is the only party that knows the series. So the
 * domain contract stays untouched.
 *
 * Nullable but not optional — opening a series is stated by passing null, so no
 * caller can mean it by leaving an argument out.
 *
 * The same CreatePort interface as OmnipayCreatePort, a different implementation.
 * Which one the caller is given IS the scenario: nothing in the request separates a
 * subscription's first charge from a standalone checkout, since both are
 * cardholder-initiated with nothing before them.
 */
function seriesRequest(
    PaymentInitiation $initiation = PaymentInitiation::MerchantRecurring,
    CaptureMethod $captureMethod = CaptureMethod::Manual,
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

/** @return array{0: OmnipayRebillingCreatePort, 1: callable(): array} */
function seriesPort(?string $storedReference, ?PaymentIntentId $genesis): array
{
    $seen = [];

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('authorizeRebilling')->once()
        ->andReturnUsing(function (...$args) use (&$seen) {
            $seen = $args;

            return AuthorizationResult::succeeded('auth_1');
        });
    // The ordinary verbs must not be reached: a series payment is authorize-only.
    $gateway->shouldReceive('charge')->never();
    $gateway->shouldReceive('authorize')->never();

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('saveForPaymentIntent')->zeroOrMoreTimes();

    if ($genesis === null) {
        $txRepo->shouldReceive('findForPaymentIntent')->never();
    } else {
        $txRepo->shouldReceive('findForPaymentIntent')->once()
            ->with($genesis->toString())->andReturn($storedReference);
    }

    return [
        new OmnipayRebillingCreatePort($gateway, $txRepo, GatewayId::generate(), $genesis),
        // A regular closure, not an arrow fn: those capture by value.
        static function () use (&$seen): array { return $seen; },
    ];
}

it('resolves the genesis payment intent to the reference the acquirer wants', function () {
    [$port, $seen] = seriesPort('1110000000123456', PaymentIntentId::generate());

    $port->create(seriesRequest());

    // (gatewayId, instrument, amount, initiation, genesisReference, ...)
    expect($seen()[4])->toBe('1110000000123456')
        ->and($seen()[3])->toBe(PaymentInitiation::MerchantRecurring);
});

it('asks for no reference when null was passed to say this payment opens the series', function (PaymentInitiation $initiation) {
    // Absent genesis is meaningful here rather than a gap — inside a series it says
    // nothing precedes this payment. That is exactly what the same absence could not
    // mean on the ordinary path, where it also meant "no series at all".
    [$port, $seen] = seriesPort(null, null);

    $port->create(seriesRequest($initiation));

    expect($seen()[4])->toBeNull();
})->with([
    PaymentInitiation::CardholderInitiated,
    PaymentInitiation::MerchantUnscheduled,
]);

it('passes nothing rather than an id the acquirer never saw, when the genesis has no reference', function () {
    [$port, $seen] = seriesPort(null, PaymentIntentId::generate());

    $port->create(seriesRequest());

    expect($seen()[4])->toBeNull();
});

it('refuses Immediate capture as a wiring error, not as a decline', function () {
    // The subscription domain consumes an Authorized intent and captures it; that
    // split is what makes "one payment intent activates at most one subscription"
    // true. Immediate would record Charged and fail that check — after the acquirer
    // had already taken the money.
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('authorizeRebilling')->never();

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);

    $port = new OmnipayRebillingCreatePort($gateway, $txRepo, GatewayId::generate(), null);

    $thrown = null;

    try {
        $port->create(seriesRequest(captureMethod: CaptureMethod::Immediate));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    // The marker is the point: without it the router folds this into a failed result
    // and the stream records an issuer decline for a request no issuer ever saw.
    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class)
        ->and($thrown->getMessage())->toContain('Immediate capture cannot be used');
});

it('reports no converted amount, because an authorization has settled nothing', function () {
    [$port] = seriesPort(null, null);

    $outcome = $port->create(seriesRequest());

    expect($outcome->convertedAmount)->toBeNull()
        ->and($outcome->challenge)->toBeNull();
});
