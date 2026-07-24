<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Risk\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\Risk\IpAddress;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreateOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskAction;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskDecisionPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskOutcome;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\FraudScreeningCreatePort;

function makeScreeningConnection(): ConnectionContext
{
    return new ConnectionContext(new IpAddress('203.0.113.7'), 'Mozilla/5.0');
}

function makeCardCreateRequest(
    ?ChallengeResult $challengeResult = null,
    PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated,
): CreateRequest {
    return new CreateRequest(
        paymentIntentId: PaymentIntentId::generate(),
        amount: new Money(1000, new Currency('USD')),
        instrument: new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        captureMethod: CaptureMethod::Immediate,
        billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
        challengeResult: $challengeResult,
        initiation: $initiation,
    );
}

it('returns a ThreeDS challenge and skips the gateway when the fraud rules require a step-up', function () {
    $inner = Mockery::mock(CreatePort::class);
    $inner->shouldNotReceive('create');

    $risk = Mockery::mock(RiskDecisionPort::class);
    $risk->shouldReceive('decide')->once()->andReturn(new RiskOutcome(RiskAction::Require3ds));

    $port = new FraudScreeningCreatePort($inner, $risk, GatewayId::generate(), makeScreeningConnection());

    $request = makeCardCreateRequest();
    $outcome = $port->create($request);

    expect($outcome->challenge)->toBeInstanceOf(ThreeDSChallenge::class)
        ->and($outcome->challenge->transactionId())->toBe($request->paymentIntentId->toString())
        ->and($outcome->convertedAmount)->toBeNull();
});

it('delegates to the wrapped port when the fraud rules allow', function () {
    $delegated = new CreateOutcome(convertedAmount: new Money(1000, new Currency('USD')));

    $inner = Mockery::mock(CreatePort::class);
    $inner->shouldReceive('create')->once()->andReturn($delegated);

    $risk = Mockery::mock(RiskDecisionPort::class);
    $risk->shouldReceive('decide')->once()->andReturn(new RiskOutcome(RiskAction::Allow));

    $port = new FraudScreeningCreatePort($inner, $risk, GatewayId::generate(), makeScreeningConnection());

    expect($port->create(makeCardCreateRequest()))->toBe($delegated);
});

it('skips screening and delegates when a successful 3DS authentication is already present', function () {
    $delegated = new CreateOutcome;

    $inner = Mockery::mock(CreatePort::class);
    $inner->shouldReceive('create')->once()->andReturn($delegated);

    $risk = Mockery::mock(RiskDecisionPort::class);
    $risk->shouldNotReceive('decide');

    $port = new FraudScreeningCreatePort($inner, $risk, GatewayId::generate(), makeScreeningConnection());

    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-base64',
        ECICode::VisaSuccessful,
        '11111111-1111-1111-1111-111111111111',
        '22222222-2222-2222-2222-222222222222',
        ThreeDSVersion::V220,
    );

    expect($port->create(makeCardCreateRequest($threeDS)))->toBe($delegated);
});

it('skips screening and delegates for a merchant-initiated payment', function () {
    $delegated = new CreateOutcome;

    $inner = Mockery::mock(CreatePort::class);
    $inner->shouldReceive('create')->once()->andReturn($delegated);

    $risk = Mockery::mock(RiskDecisionPort::class);
    $risk->shouldNotReceive('decide');

    $port = new FraudScreeningCreatePort($inner, $risk, GatewayId::generate(), makeScreeningConnection());

    $request = makeCardCreateRequest(initiation: PaymentInitiation::MerchantRecurring);

    expect($port->create($request))->toBe($delegated);
});

it('skips screening and delegates for a non-card instrument', function () {
    $delegated = new CreateOutcome;

    $inner = Mockery::mock(CreatePort::class);
    $inner->shouldReceive('create')->once()->andReturn($delegated);

    $risk = Mockery::mock(RiskDecisionPort::class);
    $risk->shouldNotReceive('decide');

    $port = new FraudScreeningCreatePort($inner, $risk, GatewayId::generate(), makeScreeningConnection());

    $request = new CreateRequest(
        paymentIntentId: PaymentIntentId::generate(),
        amount: new Money(1000, new Currency('USD')),
        instrument: new Cash,
        captureMethod: CaptureMethod::Immediate,
        billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    expect($port->create($request))->toBe($delegated);
});
