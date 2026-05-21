<?php

namespace Techork\PaymentService\Laravel\Encryption;

use Techork\PaymentService\Common\Contract\EncryptInterface;

interface EncrypterAwareInterface
{
    public function setEncrypter(EncryptInterface $encrypter): void;
}
