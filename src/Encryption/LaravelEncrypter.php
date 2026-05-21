<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Encryption;

use Illuminate\Contracts\Encryption\Encrypter;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;

final readonly class LaravelEncrypter implements DecryptInterface, EncryptInterface
{
    public function __construct(private Encrypter $encrypter) {}

    public function encrypt(string $data): string
    {
        return $this->encrypter->encryptString($data);
    }

    public function decrypt(string $data): string
    {
        return $this->encrypter->decryptString($data);
    }
}
