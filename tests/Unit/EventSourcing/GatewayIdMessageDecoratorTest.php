<?php

declare(strict_types=1);

use EventSauce\EventSourcing\Header;
use EventSauce\EventSourcing\Message;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Log\Context\Repository as ContextRepository;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCreated;
use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use Techork\PaymentService\Laravel\EventSourcing\Decorators\GatewayIdMessageDecorator;

/**
 * Which gateway a recorded event belongs to.
 *
 * The gateway id is not part of any event payload — the domain is deliberately ignorant of
 * routing — so this header is the only place the stream records which acquirer a payment went
 * through. Everything that reconciles history against a provider (a settlement file, a
 * chargeback, "who took this money") reads it from here, which makes both halves worth
 * pinning: that the value lands under the key later readers look for, and that stamping it
 * does not disturb anything else already on the message.
 */
function gatewayIdDecoratorFor(mixed $gatewayId = null): GatewayIdMessageDecorator
{
    $context = new ContextRepository(new EventDispatcher);

    if ($gatewayId !== null) {
        $context->add('gateway_id', $gatewayId);
    }

    return new GatewayIdMessageDecorator($context);
}

function gatewayIdDecoratorMessage(array $headers = []): Message
{
    return new Message(
        new CheckoutCreated(new Money(5000, new Currency('USD')), 'A decorated checkout', null),
        $headers,
    );
}

it('stamps the gateway id from the context under the header readers look for', function () {
    $decorated = gatewayIdDecoratorFor('gw-nuvei-1')->decorate(gatewayIdDecoratorMessage());

    // `header()` with the class constant is the reader's side of the contract; the literal is
    // asserted alongside it because the constant's *value* is what is already written into
    // stored rows — renaming it silently orphans every event on record.
    expect($decorated->header(GatewayIdMessageDecorator::GATEWAY_ID))->toBe('gw-nuvei-1')
        ->and($decorated->header('__gateway_id'))->toBe('gw-nuvei-1')
        ->and(GatewayIdMessageDecorator::GATEWAY_ID)->toBe('__gateway_id');
});

it('leaves the payload and the headers already on the message untouched', function () {
    $id = CheckoutId::generate();
    $message = gatewayIdDecoratorMessage([
        Header::AGGREGATE_ROOT_ID => $id,
        Header::AGGREGATE_ROOT_VERSION => 3,
    ]);

    $decorated = gatewayIdDecoratorFor('gw-stripe-1')->decorate($message);

    // Decorators run in a chain, so one that dropped the headers set before it would erase the
    // version and the aggregate id — the message repository refuses a message with no
    // aggregate root, which would turn this into a write failure rather than a silent loss.
    expect($decorated->aggregateRootId()?->toString())->toBe($id->toString())
        ->and($decorated->header(Header::AGGREGATE_ROOT_VERSION))->toBe(3)
        ->and($decorated->payload())->toBe($message->payload())
        // Immutable: `withHeaders()` clones, and the chain relies on the original being
        // reusable.
        ->and($message->header(GatewayIdMessageDecorator::GATEWAY_ID))->toBeNull();
});

it('replaces a gateway id the message already carried', function () {
    $message = gatewayIdDecoratorMessage([GatewayIdMessageDecorator::GATEWAY_ID => 'gw-explicit']);

    // `Message::withHeaders($new)` merges as `$new + $existing`, so the decorator's value wins
    // over one the caller set. Pinned as it stands, not endorsed: the ambient context is the
    // weaker source of truth, and a caller recording an event *about* a payment placed on
    // another gateway — an import, a settlement-file replay — cannot state which one it was.
    // Harmless today only because nothing else writes this header.
    expect(gatewayIdDecoratorFor('gw-ambient')->decorate($message)->header(GatewayIdMessageDecorator::GATEWAY_ID))
        ->toBe('gw-ambient');
});

it('stamps an empty string when the context carries no gateway id', function () {
    $decorated = gatewayIdDecoratorFor()->decorate(gatewayIdDecoratorMessage());

    // The `(string)` cast turns a missing context value into `''` rather than leaving the
    // header off. Pinned as it stands, not endorsed: a reader cannot tell "recorded outside a
    // gateway call" from "recorded with a blank gateway id", and the header is present either
    // way, so `header()` returning non-null is not evidence of a routed payment.
    expect($decorated->header(GatewayIdMessageDecorator::GATEWAY_ID))->toBe('');
});

it('coerces a non-string gateway id to its string form', function () {
    // Laravel's context is an untyped bag and callers put whatever they have in it — the
    // gateway id arrives as an int from a database column often enough. The header has to be a
    // scalar the JSON payload can hold, and the cast is what guarantees it.
    expect(gatewayIdDecoratorFor(42)->decorate(gatewayIdDecoratorMessage())->header(GatewayIdMessageDecorator::GATEWAY_ID))
        ->toBe('42');
});
