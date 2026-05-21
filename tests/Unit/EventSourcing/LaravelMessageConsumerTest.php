<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\Checkout\Event\CheckoutCreated;
use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use Techork\PaymentService\Laravel\EventSourcing\Consumers\LaravelMessageConsumer;
use EventSauce\EventSourcing\Header;
use EventSauce\EventSourcing\Message;
use Illuminate\Contracts\Events\Dispatcher;
use Money\Currency;
use Money\Money;

it('dispatches domain event with message via Laravel event dispatcher', function () {
    $checkoutId = CheckoutId::generate();
    $event = new CheckoutCreated(
        new Money(5000, new Currency('USD')),
        'Test checkout',
        'https://example.com/callback',
        null,
    );

    $message = new Message($event, [
        Header::AGGREGATE_ROOT_ID => $checkoutId,
    ]);

    $dispatchedEvent = null;
    $dispatchedPayload = null;

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->withArgs(function (string $eventName, array $payload) use (&$dispatchedEvent, &$dispatchedPayload) {
            $dispatchedEvent = $eventName;
            $dispatchedPayload = $payload;

            return true;
        });

    $consumer = new LaravelMessageConsumer($dispatcher);
    $consumer->handle($message);

    expect($dispatchedEvent)->toBe(CheckoutCreated::class)
        ->and($dispatchedPayload)->toHaveCount(2)
        ->and($dispatchedPayload[0])->toBeInstanceOf(CheckoutCreated::class)
        ->and($dispatchedPayload[1])->toBeInstanceOf(Message::class);
});
