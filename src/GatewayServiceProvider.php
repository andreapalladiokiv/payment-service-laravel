<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel;

use EventSauce\EventSourcing\DefaultHeadersDecorator;
use EventSauce\EventSourcing\MessageDecorator;
use EventSauce\EventSourcing\MessageDecoratorChain;
use EventSauce\EventSourcing\MessageDispatcher;
use EventSauce\EventSourcing\MessageDispatcherChain;
use EventSauce\EventSourcing\MessageRepository;
use EventSauce\EventSourcing\Serialization\ConstructingMessageSerializer;
use EventSauce\EventSourcing\Snapshotting\SnapshotRepository;
use EventSauce\EventSourcing\SynchronousMessageDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Validation\Factory;
use Illuminate\Validation\InvokableValidationRule;
use Omnipay\Omnipay;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Mapping\Loader\LoaderChain;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Domain\Checkout\CheckoutAggregateRepositoryInterface;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregateRepositoryInterface;
use Techork\PaymentService\Domain\Subscription\SubscriptionAggregateRepositoryInterface;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardReferenceRepository;
use Techork\PaymentService\Gateway\GatewayFactory;
use Techork\PaymentService\Laravel\Encryption\EncrypterAwareInterface;
use Techork\PaymentService\Laravel\Encryption\LaravelEncrypter;
use Techork\PaymentService\Laravel\EventSourcing\Consumers\LaravelMessageConsumer;
use Techork\PaymentService\Laravel\EventSourcing\Decorators\GatewayIdMessageDecorator;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\CheckoutAggregateRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateMessageRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateSnapshotRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\PaymentIntentAggregateRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\SubscriptionAggregateRepository;
use Techork\PaymentService\Laravel\EventSourcing\Serialization\SymfonyPayloadSerializer;
use Techork\PaymentService\Laravel\Logger\Sanitizer\ByPropertyNameSanitizer;
use Techork\PaymentService\Laravel\Logger\Sanitizer\CardNumberSanitizer;
use Techork\PaymentService\Laravel\Logger\Sanitizer\EmailSanitizer;
use Techork\PaymentService\Laravel\Logger\Sanitizer\PhoneNumberSanitizer;
use Techork\PaymentService\Laravel\Logger\SanitizingLogger;
use Techork\PaymentService\Laravel\Repository\EloquentCustomerRepository;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayCredentialRepository;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayInstrumentRepository;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository;
use Techork\PaymentService\Laravel\Repository\EloquentVirtualCardReferenceRepository;
use Techork\PaymentService\Laravel\Rules\Country;
use Techork\PaymentService\Laravel\Rules\Currency;
use Techork\PaymentService\Laravel\Rules\Duration;
use Techork\PaymentService\Laravel\Rules\Phone;
use Techork\PaymentService\Laravel\Rules\State;
use Techork\PaymentService\Laravel\Serializer\ChallengeNormalizer;
use Techork\PaymentService\Laravel\Serializer\ChallengeResultNormalizer;
use Techork\PaymentService\Laravel\Serializer\PaymentInstrumentNormalizer;
use Techork\PaymentService\Laravel\Serializer\PhoneNumberNormalizer;
use Techork\PaymentService\Laravel\Serializer\PiiAttributeLoader;
use Techork\PaymentService\Laravel\Serializer\PiiAwareObjectNormalizer;
use Techork\PaymentService\Laravel\Serializer\UuidNormalizer;
use Techork\PaymentService\Laravel\Shredding\EloquentPiiStore;
use Techork\PaymentService\Laravel\Shredding\PiiStore;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;
use Techork\PaymentService\Gateway\PaymentGatewayRouter;

class GatewayServiceProvider extends PackageServiceProvider
{
    public $singletons = [
        GatewayCredentialRepository::class => EloquentGatewayCredentialRepository::class,
        CustomerRepository::class => EloquentCustomerRepository::class,
        GatewayInstrumentRepository::class => EloquentGatewayInstrumentRepository::class,
        GatewayTransactionRepository::class => EloquentGatewayTransactionRepository::class,
        VirtualCardReferenceRepository::class => EloquentVirtualCardReferenceRepository::class,
        EncryptInterface::class => LaravelEncrypter::class,
        DecryptInterface::class => LaravelEncrypter::class,
        PiiStore::class => EloquentPiiStore::class,
    ];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('gateway')
            ->hasMigrations([
                'create_gateways_table',
                'create_gateway_references_table',
                'create_gateway_customers_table',
                'extend_webhook_calls',
                'add_reference_index_to_gateway_references',
                'add_metadata_to_gateway_references',
                'create_shredding_values_table',
            ]);
    }

    public function bootingPackage(): void
    {
        $this->app[Factory::class]->extend('country', function ($attribute, $value, $parameters, $validator) {
            return InvokableValidationRule::make(new Country(...$parameters))
                ->setValidator($validator)
                ->passes($attribute, $value);
        });
        $this->app[Factory::class]->extend('phone', function ($attribute, $value, $parameters, $validator) {
            return InvokableValidationRule::make(new Phone(...$parameters))
                ->setValidator($validator)
                ->passes($attribute, $value);
        });
        $this->app[Factory::class]->extend('state', function ($attribute, $value, $parameters, $validator) {
            return InvokableValidationRule::make(new State(...$parameters))
                ->setValidator($validator)
                ->passes($attribute, $value);
        });
        $this->app[Factory::class]->extend('currency', function ($attribute, $value, $parameters, $validator) {
            return InvokableValidationRule::make(new Currency)
                ->setValidator($validator)
                ->passes($attribute, $value);
        });
        $this->app[Factory::class]->extend('duration', function ($attribute, $value, $parameters, $validator) {
            return InvokableValidationRule::make(new Duration)
                ->setValidator($validator)
                ->passes($attribute, $value);
        });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PaymentGatewayInterface::class, PaymentGatewayRouter::class);

        $this->app->singleton(GatewayLoggerInterface::class, fn (Application $app) =>
            new SanitizingLogger($app[LoggerInterface::class], LogLevel::INFO, ...$app->tagged('sanitizer')));

        $this->app->singleton(GatewayFactory::class, function (Application $app) {
            $manifest = $app->make(PackageManifest::class);
            $factory = new LaravelGatewayFactory($app[CustomerRepository::class], $app['config']);
            $factory->replace($this->discoverGateways($manifest));
            Omnipay::setFactory($factory);

            return $factory;
        });

        $this->app->singleton(Serializer::class, function (Application $app) {
            $metadataFactory = new ClassMetadataFactory(
                new LoaderChain([new AttributeLoader, new PiiAttributeLoader]),
            );

            // `PropertyNormalizer` (not `ObjectNormalizer`) because our VOs
            // hold their state in private properties and surface it only via
            // `jsonSerialize`/`__toString` — `ObjectNormalizer` would read
            // an empty public API and fail to round-trip them. The reflection
            // path bypasses constructors on rebuild, which is fine here: the
            // event stream only round-trips through itself.
            //
            // `JsonSerializableNormalizer` is intentionally absent: it would
            // collapse the same VOs to scalars on the way out, breaking the
            // symmetry with the property-based denormalize path.
            $piiAware = new PiiAwareObjectNormalizer(
                new PropertyNormalizer(
                    classMetadataFactory: $metadataFactory,
                    propertyTypeExtractor: new ReflectionExtractor,
                ),
                $app[PiiStore::class],
                $metadataFactory,
            );

            return new Serializer([
                new UuidNormalizer,
                new PhoneNumberNormalizer,
                new BackedEnumNormalizer,
                new DateTimeNormalizer,
                new ArrayDenormalizer,
                // Interface-typed slots in aggregates need a concrete-class
                // resolver before `PiiAwareObjectNormalizer` can look up per-
                // class PII metadata. Each visitor-based normalizer dispatches
                // on the concrete impl and then delegates back to the PII
                // pipeline on the resolved class.
                new PaymentInstrumentNormalizer($piiAware),
                new ChallengeNormalizer($piiAware),
                new ChallengeResultNormalizer($piiAware),
                $piiAware,
            ]);
        });

        $this->app->singleton(MessageRepository::class, fn (Application $app) => new IlluminateMessageRepository(
            $app[DatabaseManager::class]->connection(),
            'stored_events',
            new ConstructingMessageSerializer(
                payloadSerializer: new SymfonyPayloadSerializer($app[Serializer::class]),
            ),
        ));

        $this->app->singleton(SnapshotRepository::class, fn (Application $app) => new IlluminateSnapshotRepository($app[DatabaseManager::class]->connection()));

        $this->app->singleton(SynchronousMessageDispatcher::class, fn (Application $app) => new SynchronousMessageDispatcher($app[LaravelMessageConsumer::class]));

        $this->app->singleton(DefaultHeadersDecorator::class, fn () => new DefaultHeadersDecorator);

        $this->app->tag([SynchronousMessageDispatcher::class], 'es.message_dispatcher');
        $this->app->tag([DefaultHeadersDecorator::class, GatewayIdMessageDecorator::class], 'es.message_decorator');

        $this->app->singleton(ByPropertyNameSanitizer::class, function () {
            return new ByPropertyNameSanitizer('first_name', 'last_name', 'line', 'line_extra', 'holder');
        });

        $this->app->tag([CardNumberSanitizer::class, EmailSanitizer::class, PhoneNumberSanitizer::class, ByPropertyNameSanitizer::class], 'sanitizer');

        $this->app->singleton(MessageDispatcher::class, fn (Application $app) => new MessageDispatcherChain(...$app->tagged('es.message_dispatcher')));

        $this->app->singleton(MessageDecorator::class, fn (Application $app) => new MessageDecoratorChain(...$app->tagged('es.message_decorator')));

        $this->registerAggregateRepositories();

        $this->app->resolving(
            EncrypterAwareInterface::class,
            fn (EncrypterAwareInterface $instance, Application $app) => $instance->setEncrypter($app[EncryptInterface::class]),
        );
    }

    private function registerAggregateRepositories(): void
    {
        $repositories = [
            CheckoutAggregateRepositoryInterface::class => CheckoutAggregateRepository::class,
            PaymentIntentAggregateRepositoryInterface::class => PaymentIntentAggregateRepository::class,
            SubscriptionAggregateRepositoryInterface::class => SubscriptionAggregateRepository::class,
        ];

        foreach ($repositories as $interface => $implementation) {
            $this->app->singleton($interface, fn (Application $app) => new $implementation(
                $app[MessageRepository::class],
                $app[SnapshotRepository::class],
                $app[MessageDispatcher::class],
                $app[MessageDecorator::class],
            ));
        }
    }

    /**
     * @return array<string, class-string<Gateway>>
     */
    private static function discoverGateways(PackageManifest $manifest): array
    {
        $classes = array_filter($manifest->config('gateway'), function ($class) {
            return is_string($class) && class_exists($class) && is_a($class, Gateway::class, true);
        });
        $gateways = [];

        foreach ($classes as $class) {
            $gateways[(new $class)->getName()] = $class;
        }

        return $gateways;
    }
}
