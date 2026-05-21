<?php

namespace Techork\PaymentService\Laravel\Logger;

interface SanitizerInterface
{
    public function match(string $name, $value): bool;

    public function mask(string $name, mixed $value): mixed;
}