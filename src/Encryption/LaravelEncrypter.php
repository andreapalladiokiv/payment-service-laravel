<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Encryption;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Override;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;

final readonly class LaravelEncrypter implements DecryptInterface, EncryptInterface
{
    /**
     * `StringEncrypter`, which is where `encryptString()`/`decryptString()` are
     * declared and therefore the only thing this class needs. `Encrypter` — the
     * contract the binding used to name — declares neither.
     *
     * NOT the intersection of the two, even though Laravel's own encrypter
     * satisfies both and the intersection reads as the more precise statement. The
     * container cannot autowire one: it identifies a dependency through
     * {@see \Illuminate\Support\Reflector::getParameterClassName()}, which answers
     * only for a `ReflectionNamedType`, so an intersection is taken for a primitive
     * and every resolution of this class fails with an unresolvable dependency. As
     * this backs `EncryptInterface` and `DecryptInterface`, that took the gateway
     * router with it — nothing could open a payment. `encrypter` is aliased to
     * `StringEncrypter` as well as to `Encrypter`, so naming one resolves.
     */
    public function __construct(private StringEncrypter $encrypter) {}

    #[Override]
    public function encrypt(string $data): string
    {
        return $this->encrypter->encryptString($data);
    }

    #[Override]
    public function decrypt(string $data): string
    {
        return $this->encrypter->decryptString($data);
    }
}
