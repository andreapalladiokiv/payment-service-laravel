<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Laravel\Encryption\LaravelEncrypter;

/**
 * These are container tests, not encryption tests. What broke once was the
 * constructor's TYPE: an intersection of `Encrypter&StringEncrypter` reads as the
 * more precise statement and is unautowirable, because the container identifies a
 * dependency through a `ReflectionNamedType` and takes anything else for a
 * primitive. Nothing about encrypting says so — only resolving does.
 *
 * The blast radius is the reason it is tested here rather than left to whoever
 * resolves it: {@see EncryptInterface} and {@see DecryptInterface} both point at
 * this class, and the gateway router takes a decrypter, so an unresolvable
 * constructor here means no payment can be opened at all.
 */
function encrypterApp(): Application
{
    $app = new Application(sys_get_temp_dir());

    // What Laravel's own EncryptionServiceProvider binds. `registerCoreContainerAliases`
    // has already aliased both contracts to this key, which is what the parameter
    // type has to name one of.
    $app->instance('encrypter', new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));

    return $app;
}

it('is autowirable, which is the whole of what the container needs from it', function () {
    $encrypter = encrypterApp()->make(LaravelEncrypter::class);

    expect($encrypter)->toBeInstanceOf(LaravelEncrypter::class);
});

it('resolves through both contracts the provider maps onto it', function () {
    // The shape of GatewayServiceProvider's `$singletons` entries, which is where a
    // constructor the container cannot read actually surfaces.
    $app = encrypterApp();
    $app->singleton(EncryptInterface::class, LaravelEncrypter::class);
    $app->singleton(DecryptInterface::class, LaravelEncrypter::class);

    expect($app->make(EncryptInterface::class))->toBeInstanceOf(LaravelEncrypter::class)
        ->and($app->make(DecryptInterface::class))->toBeInstanceOf(LaravelEncrypter::class);
});

it('round-trips a card number through the encrypter it was handed', function () {
    $app = encrypterApp();
    $app->singleton(EncryptInterface::class, LaravelEncrypter::class);
    $app->singleton(DecryptInterface::class, LaravelEncrypter::class);

    $ciphertext = $app->make(EncryptInterface::class)->encrypt('4111111111111111');

    expect($ciphertext)->not->toBe('4111111111111111')
        ->and($app->make(DecryptInterface::class)->decrypt($ciphertext))->toBe('4111111111111111');
});

it('takes the string encrypter, not the contract that cannot answer for it', function () {
    // Both are aliased to `encrypter` and Laravel's implementation satisfies both,
    // so a wrong choice here is invisible at runtime — until it is the pair of them
    // at once, which no container can supply by reflection.
    $parameter = new ReflectionParameter([LaravelEncrypter::class, '__construct'], 0);

    expect($parameter->getType())->toBeInstanceOf(ReflectionNamedType::class)
        ->and((string) $parameter->getType())->toBe(StringEncrypter::class)
        ->and((string) $parameter->getType())->not->toBe(EncrypterContract::class);
});
