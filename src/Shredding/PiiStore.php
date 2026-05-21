<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Shredding;

interface PiiStore
{
    public function store(string $plaintext): string;

    public function retrieve(string $hash): ?string;
}
