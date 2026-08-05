<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Http\Request;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\EventParser;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\ParsedEvent;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookSubscriber;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\NoOpGatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;
use Techork\PaymentService\Laravel\Webhook\WebhookServiceProvider;

/**
 * The provider that turns "some gateway packages are installed" into a working webhook
 * layer, at zero coverage until now. ContainerBindingsTest already checks that every class
 * it *names* is one the container could build; that is a static read of the file and
 * deliberately stops there. What it cannot see is the half of
 * this provider that has no class names in it at all: discovery through the
 * {@see PackageManifest}, the two registries that are filled by side effect inside a
 * singleton closure, and the mutual-stash trick that keeps them consistent.
 *
 * Everything here is reachable only through a real container, and each behaviour fails
 * silently rather than loudly when it regresses — an empty registry does not throw, it
 * makes every delivery a 4xx with no handler; subscribers built twice do not throw, they
 * duplicate an EventSauce dispatcher; a manifest entry read eagerly does not throw, it
 * captures pre-`Event::fake()` state in someone else's test suite.
 *
 * The manifest is stubbed rather than installed, mirroring GatewayServiceProviderFirewallTest:
 * Composer's manifest is the authority on what is installed, and a test needs to say so
 * without an install on disk.
 */
final class WebhookProviderManifestStub extends PackageManifest
{
    /**
     * @param  array<int, mixed>  $entries
     */
    public function __construct(private readonly array $entries)
    {
        parent::__construct(new Filesystem, '', '');
    }

    public function config($key): array
    {
        return $key === 'webhook' ? $this->entries : [];
    }
}

/**
 * Stands in for a gateway package's subscriber. Counts its own construction separately
 * from its subscriptions, because the two answer different questions: whether discovery
 * stayed lazy, and whether the registries were built more than once.
 *
 * Registers under a mixed-case kind on purpose — the registries key on the lowercased
 * name, and the router looks kinds up by whatever `gateway_name` a credential carries.
 */
final class WiringWebhookSubscriber implements WebhookSubscriber
{
    public static int $constructed = 0;

    public static int $subscribed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function subscribe(VerifierRegistry $verifiers, HandlerRegistry $handlers): void
    {
        self::$subscribed++;

        $verifiers->register('Wiring', new class implements SignatureVerifier
        {
            public function verify(ServerRequestInterface $request, GatewayCredential $gateway): bool
            {
                return true;
            }
        }, new class implements EventParser
        {
            public function parse(array $payload): ParsedEvent
            {
                return new ParsedEvent('thing.happened', 'evt_1', new stdClass);
            }
        });

        $handlers->register('Wiring', 'thing.happened', new class implements WebhookEventHandler
        {
            public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
            {
                return HandlerOutcome::Processed;
            }
        });
    }
}

/**
 * A subscriber with a constructor dependency — the shape every real one has, since it
 * receives its verifier, parser and handlers through the container.
 */
final class DependentWebhookSubscriber implements WebhookSubscriber
{
    public static ?PsrHttpFactory $received = null;

    public function __construct(PsrHttpFactory $psrFactory)
    {
        self::$received = $psrFactory;
    }

    public function subscribe(VerifierRegistry $verifiers, HandlerRegistry $handlers): void {}
}

/**
 * @param  array<int, mixed>  $manifest
 */
function webhookProviderApp(array $manifest): Application
{
    $app = new Application(sys_get_temp_dir());
    $app->instance(PackageManifest::class, new WebhookProviderManifestStub($manifest));

    new WebhookServiceProvider($app)->register();

    return $app;
}

beforeEach(function () {
    WiringWebhookSubscriber::$constructed = 0;
    WiringWebhookSubscriber::$subscribed = 0;
    DependentWebhookSubscriber::$received = null;
});

it('bridges a Laravel request into the PSR-7 request a verifier signs over', function () {
    // The factory takes four PSR-17 factories and the provider passes the same object to
    // all four; miss one and every webhook 500s at conversion, before any signature is
    // checked. Asserted through an actual conversion rather than on the object, because
    // what the verifiers need is a request that still carries the body they hash — and
    // what {@see WebhookRouter::identifyGateway()} needs is a parsed body, which it reads
    // instead of decoding the stream itself.
    $psr = webhookProviderApp([])->make(PsrHttpFactory::class)->createRequest(Request::create(
        'https://app.test/webhooks/payments?probe=1',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE' => 'sig-1'],
        '{"id":"evt_1"}',
    ));

    expect($psr)->toBeInstanceOf(ServerRequestInterface::class)
        ->and($psr->getMethod())->toBe('POST')
        ->and((string) $psr->getUri())->toBe('https://app.test/webhooks/payments?probe=1')
        ->and((string) $psr->getBody())->toBe('{"id":"evt_1"}')
        ->and($psr->getHeaderLine('signature'))->toBe('sig-1')
        ->and($psr->getParsedBody())->toBe(['id' => 'evt_1']);
});

it('shares one PSR-7 bridge', function () {
    $app = webhookProviderApp([]);

    expect($app->make(PsrHttpFactory::class))->toBe($app->make(PsrHttpFactory::class));
});

it('instantiates no subscriber until a registry is asked for', function () {
    // The documented reason discovery is deferred into the singleton closures: a
    // subscriber drags handlers, recorders, aggregate repositories and EventSauce
    // dispatchers in with it. Building that graph at boot costs every unrelated test
    // something, and captures dispatcher state that a later `Event::fake()` can no longer
    // replace.
    webhookProviderApp([WiringWebhookSubscriber::class]);

    expect(WiringWebhookSubscriber::$constructed)->toBe(0);
});

it('fills both registries with what the discovered subscriber tagged', function () {
    $app = webhookProviderApp([WiringWebhookSubscriber::class]);

    $verifiers = $app->make(VerifierRegistry::class);
    $handlers = $app->make(HandlerRegistry::class);

    expect($verifiers->hasKind('wiring'))->toBeTrue()
        ->and($verifiers->verifier('wiring'))->toBeInstanceOf(SignatureVerifier::class)
        ->and($verifiers->parser('wiring'))->toBeInstanceOf(EventParser::class)
        ->and($handlers->resolve('wiring', 'thing.happened'))->toBeInstanceOf(WebhookEventHandler::class)
        // A kind is looked up by the credential's `gateway_name`, whose casing is the
        // merchant's business; only an event type is matched verbatim.
        ->and($verifiers->hasKind('WIRING'))->toBeTrue()
        ->and($handlers->resolve('WIRING', 'thing.happened'))->not->toBeNull()
        ->and($handlers->resolve('wiring', 'thing.unhandled'))->toBeNull()
        ->and($verifiers->hasKind('never-installed'))->toBeFalse();
});

it('builds both registries once when the verifiers are resolved first', function () {
    // Whichever singleton is touched first builds the pair and stashes the other back as
    // an instance. Without that stash the second resolution re-runs discovery, and the
    // router would then verify against one set of subscribers and dispatch into another —
    // a handler registered by a subscriber that no longer exists as far as the verifiers
    // are concerned.
    $app = webhookProviderApp([WiringWebhookSubscriber::class]);

    $verifiers = $app->make(VerifierRegistry::class);
    $handlers = $app->make(HandlerRegistry::class);

    expect(WiringWebhookSubscriber::$subscribed)->toBe(1)
        ->and(WiringWebhookSubscriber::$constructed)->toBe(1)
        ->and($app->make(VerifierRegistry::class))->toBe($verifiers)
        ->and($app->make(HandlerRegistry::class))->toBe($handlers);
});

it('builds both registries once when the handlers are resolved first', function () {
    // The mirror case, which is the one the queue takes: the worker resolves the handler
    // registry through the router without ever asking for a verifier.
    $app = webhookProviderApp([WiringWebhookSubscriber::class]);

    $handlers = $app->make(HandlerRegistry::class);
    $verifiers = $app->make(VerifierRegistry::class);

    expect(WiringWebhookSubscriber::$subscribed)->toBe(1)
        ->and($handlers->resolve('wiring', 'thing.happened'))->not->toBeNull()
        ->and($verifiers->hasKind('wiring'))->toBeTrue()
        ->and($app->make(HandlerRegistry::class))->toBe($handlers)
        ->and($app->make(VerifierRegistry::class))->toBe($verifiers);
});

it('ignores manifest entries that are not webhook subscribers', function () {
    // Keeps boot alive while a deploy has a package half-installed: a manifest naming a
    // class that is not on disk yet must cost that gateway its webhooks, not cost every
    // other gateway the whole layer.
    $app = webhookProviderApp([
        'Techork\\Nope\\NotInstalledSubscriber',
        stdClass::class,
        42,
        ['nested'],
        WiringWebhookSubscriber::class,
    ]);

    expect($app->make(VerifierRegistry::class)->hasKind('wiring'))->toBeTrue()
        ->and(WiringWebhookSubscriber::$subscribed)->toBe(1);
});

it('resolves a subscriber through the container, so its dependencies arrive wired', function () {
    // Subscribers are constructor-injected, not new'ed — the whole point of declaring them
    // as a class-string in composer.json rather than instantiating them. Pinned against the
    // provider's own singleton, so this also says the discovery step runs inside the
    // container that registered the bindings a subscriber may depend on.
    $app = webhookProviderApp([DependentWebhookSubscriber::class]);

    $app->make(VerifierRegistry::class);

    expect(DependentWebhookSubscriber::$received)->toBe($app->make(PsrHttpFactory::class));
});

it('leaves both registries empty and usable when no package ships a subscriber', function () {
    // The foundation has zero compile-time knowledge of any gateway, so "nothing installed"
    // is a legitimate state and must answer with empty registries rather than refuse to
    // build them.
    $app = webhookProviderApp([]);

    expect($app->make(VerifierRegistry::class)->hasKind('stripe'))->toBeFalse()
        ->and($app->make(HandlerRegistry::class)->resolve('stripe', 'anything'))->toBeNull();
});

it('defaults payment-method recording to the no-op the bridge owns', function () {
    // PaymentMethod storage is application-defined and most applications have none. The
    // default exists so an `attached`-style delivery resolves, acks and is marked processed
    // instead of failing its way through the queue's retries; an unbound contract would make
    // that a BindingResolutionException on a webhook nobody asked to handle.
    expect(webhookProviderApp([])->make(GatewayPaymentMethodRecorder::class))
        ->toBeInstanceOf(NoOpGatewayPaymentMethodRecorder::class);
});
