<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentInstrumentReferenceEraser;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentPaymentIntentRecorder;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentRefundRecorder;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentTransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\InstrumentReferenceEraser;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookSubscriber;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayAuthorizationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentIntentRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\NoOpGatewayPaymentIntentRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\NoOpGatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundProcessingRecorder;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;

/**
 * Wires the webhook layer:
 *   - {@see VerifierRegistry}: kind → (SignatureVerifier, EventParser)
 *   - {@see HandlerRegistry}: (kind, event-type) → WebhookEventHandler
 *   - {@see PsrHttpFactory}: Laravel Request → PSR-7 bridge for verifiers
 *
 * Each gateway package ships a {@see WebhookSubscriber} and declares it
 * in its `composer.json`:
 *
 *     "extra": { "laravel": { "webhook": "Techork\\...\\StripeWebhookSubscriber" } }
 *
 * This provider has zero compile-time dependencies on any specific
 * gateway. It walks the {@see PackageManifest} (mirroring the gateway-
 * factory discovery in
 * {@see \Techork\PaymentService\Laravel\GatewayServiceProvider})
 * and asks every installed subscriber to register itself onto the shared
 * registries via {@see WebhookSubscriber::subscribe}.
 *
 * Discovery is **lazy**: subscriber instantiation (and the deep DI graph
 * each one pulls in — handlers, recorders, aggregate repositories,
 * EventSauce dispatchers) only happens the first time {@see VerifierRegistry}
 * or {@see HandlerRegistry} is asked for. Tests that don't exercise
 * webhooks pay no cost; framework boot doesn't accidentally capture
 * pre-`Event::fake()` dispatcher state.
 *
 * Both registries are singletons — they hold handler instances, so the
 * router doesn't touch the container at dispatch time.
 */
final class WebhookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PsrHttpFactory::class, function (): PsrHttpFactory {
            $psr17 = new Psr17Factory;

            return new PsrHttpFactory($psr17, $psr17, $psr17, $psr17);
        });

        $this->app->bind(TransactionIdResolver::class, EloquentTransactionIdResolver::class);
        $this->app->bind(InstrumentReferenceEraser::class, EloquentInstrumentReferenceEraser::class);
        // Default: no-op. Applications with local PaymentMethod storage
        // override this binding in their own service provider.
        $this->app->bind(GatewayPaymentMethodRecorder::class, NoOpGatewayPaymentMethodRecorder::class);

        // Also no-op by default, for a stronger reason than storage: creating an
        // intent from `payment_intent.created` means accepting intents the
        // application never initiated, which only the application can decide.
        // A no-op default is also what keeps adding the handler from changing
        // any existing consumer's behaviour.
        $this->app->bind(GatewayPaymentIntentRecorder::class, NoOpGatewayPaymentIntentRecorder::class);

        $this->app->bind(GatewaySuccessRecorder::class, EloquentPaymentIntentRecorder::class);
        $this->app->bind(GatewayAuthorizationRecorder::class, EloquentPaymentIntentRecorder::class);
        $this->app->bind(GatewayFailureRecorder::class, EloquentPaymentIntentRecorder::class);
        $this->app->bind(GatewayCancellationRecorder::class, EloquentPaymentIntentRecorder::class);

        $this->app->bind(RefundProcessingRecorder::class, EloquentRefundRecorder::class);
        $this->app->bind(RefundFailureRecorder::class, EloquentRefundRecorder::class);

        // FeeRecorder has no bridge default — VirtualCard storage is
        // application-defined, so the consuming app binds its own
        // implementation in its service provider.

        // Both singletons share the same wiring step. Whichever is
        // resolved first builds both registries and stashes the unused
        // one back into the container as a pre-built instance, so the
        // second resolution is a no-op lookup.
        $this->app->singleton(VerifierRegistry::class, function (Application $app): VerifierRegistry {
            [$verifiers, $handlers] = $this->buildRegistries($app);
            $app->instance(HandlerRegistry::class, $handlers);

            return $verifiers;
        });

        $this->app->singleton(HandlerRegistry::class, function (Application $app): HandlerRegistry {
            [$verifiers, $handlers] = $this->buildRegistries($app);
            $app->instance(VerifierRegistry::class, $verifiers);

            return $handlers;
        });
    }

    /**
     * Build both registries by asking every discovered
     * {@see WebhookSubscriber} to register itself.
     *
     * @return array{0: VerifierRegistry, 1: HandlerRegistry}
     */
    private function buildRegistries(Application $app): array
    {
        $verifiers = new VerifierRegistry;
        $handlers = new HandlerRegistry;

        foreach ($this->discoverSubscribers($app->make(PackageManifest::class)) as $subscriber) {
            $subscriber->subscribe($verifiers, $handlers);
        }

        return [$verifiers, $handlers];
    }

    /**
     * Reads `extra.laravel.webhook` from the manifest of every installed
     * package and resolves each entry into a {@see WebhookSubscriber}
     * instance through the container — Laravel auto-wires the subscriber's
     * constructor dependencies (verifier, parser, handlers).
     *
     * Silently filters out entries that are missing, non-class strings,
     * or don't implement the contract — keeps boot resilient when a
     * package is half-installed during a deploy.
     *
     * @return list<WebhookSubscriber>
     */
    private function discoverSubscribers(PackageManifest $manifest): array
    {
        $classes = array_filter(
            $manifest->config('webhook'),
            static fn ($class): bool => is_string($class)
                && class_exists($class)
                && is_a($class, WebhookSubscriber::class, true),
        );

        return array_values(array_map(fn (string $class): WebhookSubscriber => $this->app->make($class), $classes));
    }
}
