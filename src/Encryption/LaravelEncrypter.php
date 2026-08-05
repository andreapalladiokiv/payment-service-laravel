<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Encryption;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Override;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;

final readonly class LaravelEncrypter implements DecryptInterface, EncryptInterface
{
    /**
     * Intersection, not just `Encrypter`: `encryptString()`/`decryptString()` are declared
     * on `StringEncrypter`, while the container binding is keyed on `Encrypter`. Laravel's
     * own encrypter implements both, so naming both is what the code actually requires.
     */
    public function __construct(private Encrypter&StringEncrypter $encrypter) {}

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
