<?php

declare(strict_types=1);

use Illuminate\Support\Reflector;
use Techork\PaymentService\Laravel\GatewayServiceProvider;
use Techork\PaymentService\Laravel\Webhook\WebhookServiceProvider;

/**
 * Every concrete class the service providers name has to be one the container can build.
 *
 * This exists because of a specific outage. `LaravelEncrypter`'s constructor was changed to
 * `Encrypter&StringEncrypter` — the more precise statement, and the one thing the container
 * cannot use: it identifies a dependency through
 * {@see Reflector::getParameterClassName()}, which answers only for a `ReflectionNamedType`
 * and takes an intersection for a primitive. Both `EncryptInterface` and `DecryptInterface`
 * map onto that class and the gateway router takes a decrypter, so nothing could open a
 * payment. Static analysis approved the change; the suite was green; the file had no test.
 *
 * The assertion deliberately uses Laravel's own `Reflector` rather than restating its rule,
 * so this keeps agreeing with the container instead of with an idea of it. It is a static
 * check, not a `make()`: what broke was reading the signature, and a real resolution would
 * drag a database and a config repository in for no extra coverage of that.
 *
 * The same intersection is harmless in `SymfonyPayloadSerializer`, which the provider
 * constructs by hand — which is exactly why the boundary being tested is "named by a
 * binding" rather than "written anywhere in the package".
 */

/**
 * @return list<array{abstract: string, concrete: class-string}>
 */
function providerClassBindings(): array
{
    $bindings = [];

    // Declared as data, so reflection reaches them directly.
    /** @var array<string, string> $singletons */
    $singletons = new ReflectionClass(GatewayServiceProvider::class)
        ->getDefaultProperties()['singletons'] ?? [];

    foreach ($singletons as $abstract => $concrete) {
        $bindings[] = ['abstract' => $abstract, 'concrete' => $concrete];
    }

    // Declared as `bind()` calls inside register(), so the only way to enumerate them
    // without booting Spatie's package machinery is to read the file. The scan is how the
    // list is obtained; the assertion below is the actual subject.
    $source = file_get_contents(
        new ReflectionClass(WebhookServiceProvider::class)->getFileName() ?: '',
    ) ?: '';

    preg_match_all(
        '/->(?:bind|singleton)\(\s*([\w\\\\]+)::class\s*,\s*([\w\\\\]+)::class\s*\)/',
        $source,
        $matches,
        PREG_SET_ORDER,
    );

    $imports = [];
    if (preg_match_all('/^use ([\w\\\\]+);$/m', $source, $useMatches)) {
        foreach ($useMatches[1] as $fqcn) {
            $parts = explode('\\', $fqcn);
            $imports[end($parts)] = $fqcn;
        }
    }

    foreach ($matches as [, $abstract, $concrete]) {
        $bindings[] = [
            'abstract' => $imports[$abstract] ?? $abstract,
            'concrete' => $imports[$concrete] ?? $concrete,
        ];
    }

    return $bindings;
}

it('finds bindings in both providers, so an empty list cannot pass silently', function () {
    // Without this the scan could quietly stop matching — a refactor of the provider, a
    // renamed property — and every assertion below would pass over nothing.
    $bindings = providerClassBindings();

    expect($bindings)->not->toBeEmpty();

    $abstracts = array_column($bindings, 'abstract');

    expect($abstracts)->toContain(Techork\PaymentService\Common\Contract\EncryptInterface::class)
        ->and($abstracts)->toContain(Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver::class);
});

it('names concrete classes that exist and implement what they are bound to', function () {
    foreach (providerClassBindings() as ['abstract' => $abstract, 'concrete' => $concrete]) {
        expect(class_exists($concrete))->toBeTrue("Bound concrete class {$concrete} does not exist");
        expect(is_a($concrete, $abstract, true))->toBeTrue("{$concrete} is not a {$abstract}");
    }
});

it('can be autowired, which is the whole of what the container needs', function () {
    foreach (providerClassBindings() as ['concrete' => $concrete]) {
        $constructor = new ReflectionClass($concrete)->getConstructor();

        if ($constructor === null) {
            continue;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isOptional() || $parameter->isVariadic()) {
                continue;
            }

            $type = $parameter->getType();

            // A class the container can resolve, or a builtin it will refuse either way —
            // what must never happen is a required parameter it reads as neither, which is
            // what an intersection or a union of classes looks like to it.
            $resolvable = Reflector::getParameterClassName($parameter) !== null
                || ($type instanceof ReflectionNamedType && $type->isBuiltin());

            expect($resolvable)->toBeTrue(
                "{$concrete}::__construct() parameter \${$parameter->getName()} is typed "
                .($type === null ? 'not at all' : (string) $type)
                .', which the container cannot identify as a class — it resolves only a ReflectionNamedType.',
            );
        }
    }
});
