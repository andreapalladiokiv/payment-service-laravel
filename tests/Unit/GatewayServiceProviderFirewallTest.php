<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Techork\PaymentService\Firewall\Dsl\FactSchema;
use Techork\PaymentService\Firewall\Dsl\FieldType;
use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;
use Techork\PaymentService\Firewall\PaymentIntent\PaymentIntentFactSchema;
use Techork\PaymentService\Laravel\GatewayServiceProvider;

/**
 * Stands in for the manifest Composer writes, so a test can say which packages
 * declared `extra.laravel.firewall` without an install on disk.
 */
final class FirewallManifestStub extends PackageManifest
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
        return $key === 'firewall' ? $this->entries : [];
    }
}

/** A second vocabulary, so ambiguity can be provoked. */
final class SecondFactSchema implements FactSchema
{
    public function roots(): array
    {
        return ['whatever'];
    }

    public function typeOf(string $path): FieldType
    {
        return FieldType::Mixed;
    }
}

/**
 * @param  array<int, mixed>  $manifest
 * @param  array<string, mixed>  $config
 */
function firewallApp(string $base, array $manifest, array $config = []): Application
{
    $app = new Application($base);
    $app->singleton('config', fn () => new Repository($config));
    $app->instance(PackageManifest::class, new FirewallManifestStub($manifest));

    new GatewayServiceProvider($app)->packageRegistered();

    return $app;
}

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/firewall-provider-'.bin2hex(random_bytes(6));
    mkdir($this->base.'/storage/framework/cache', 0777, true);
    $this->declared = [PaymentIntentFactSchema::class];
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->base));
});

it('binds the discovered schema and an evaluator built on it', function () {
    $app = firewallApp($this->base, $this->declared);

    expect($app->make(FactSchema::class))->toBeInstanceOf(PaymentIntentFactSchema::class)
        ->and($app->make(RuleEvaluator::class))->toBeInstanceOf(RuleEvaluator::class)
        ->and($app->make('firewall.parse_cache'))->toBeInstanceOf(FilesystemAdapter::class);
});

it('registers nothing when no package declares a schema', function () {
    // How the provider learns the firewall package is absent. The manifest is
    // the authority on what is installed; the autoloader is not.
    $app = firewallApp($this->base, []);

    expect($app->bound(FactSchema::class))->toBeFalse()
        ->and($app->bound(RuleEvaluator::class))->toBeFalse()
        ->and($app->bound('firewall.parse_cache'))->toBeFalse();
});

it('ignores manifest entries that are not fact schemas', function () {
    $app = firewallApp($this->base, ['Not\\A\\Class', stdClass::class, PaymentIntentFactSchema::class]);

    expect($app->make(FactSchema::class))->toBeInstanceOf(PaymentIntentFactSchema::class);
});

it('treats the same schema declared twice as one', function () {
    // The foundation declares it and so does the split firewall package. They
    // are mutually exclusive through `replace`, but a half-finished install
    // must not read as a disagreement about the vocabulary.
    $app = firewallApp($this->base, [PaymentIntentFactSchema::class, PaymentIntentFactSchema::class]);

    expect($app->make(FactSchema::class))->toBeInstanceOf(PaymentIntentFactSchema::class);
});

it('refuses to guess between two schemas', function () {
    // Picking one silently would evaluate every rule against the wrong fact
    // roots — rules rejected at authoring time, or quietly never matching.
    firewallApp($this->base, [PaymentIntentFactSchema::class, SecondFactSchema::class]);
})->throws(RuntimeException::class, 'Several firewall fact schemas were discovered');

it('lets configuration settle the ambiguity', function () {
    $app = firewallApp(
        $this->base,
        [PaymentIntentFactSchema::class, SecondFactSchema::class],
        ['gateway' => ['firewall' => ['schema' => SecondFactSchema::class]]],
    );

    expect($app->make(FactSchema::class))->toBeInstanceOf(SecondFactSchema::class);
});

it('evaluates a rule in the discovered vocabulary', function () {
    expect(firewallApp($this->base, $this->declared)->make(RuleEvaluator::class)->matches(
        ['payment_intent.amount' => ['min' => 5000]],
        ['payment_intent' => ['amount' => 9900]],
    ))->toBeTrue();
});

it('persists the parsed expression beyond the request', function () {
    // The whole point of the pool. Symfony's default pool is per-instance and
    // dies with the request, so every payment re-parses every rule in the chain
    // — correct, and roughly twenty times the cost on a chain of twenty rules.
    // Nothing observable breaks when this regresses, hence the test.
    $directory = $this->base.'/storage/framework/cache/firewall';

    firewallApp($this->base, $this->declared)->make(RuleEvaluator::class)->matches(
        ['payment_intent.amount' => ['min' => 5000]],
        ['payment_intent' => ['amount' => 9900]],
    );

    expect(glob($directory.'/*/*'))->toHaveCount(1);

    // A second container stands in for the next request: same rule, same key,
    // served off disk rather than parsed again.
    firewallApp($this->base, $this->declared)->make(RuleEvaluator::class)->matches(
        ['payment_intent.amount' => ['min' => 5000]],
        ['payment_intent' => ['amount' => 100]],
    );

    expect(glob($directory.'/*/*'))->toHaveCount(1);
});

it('honours a configured cache path', function () {
    $elsewhere = $this->base.'/custom-firewall-cache';

    firewallApp($this->base, $this->declared, ['gateway' => ['firewall' => ['cache_path' => $elsewhere]]])
        ->make(RuleEvaluator::class)
        ->matches(['payment_intent.amount' => ['min' => 5000]], ['payment_intent' => ['amount' => 9900]]);

    expect(glob($elsewhere.'/*/*'))->toHaveCount(1);
});
