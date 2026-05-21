<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Shredding;

use Override;
use Techork\PaymentService\Laravel\Models\ShreddingValue;

final class EloquentPiiStore implements PiiStore
{
    /** @var array<string, ?string> */
    private array $cache = [];

    #[Override]
    public function store(string $plaintext): string
    {
        if (array_key_exists($hash = hash('sha256', $plaintext), $this->cache)) {
            return $hash;
        }

        $hash = ShreddingValue::query()->createOrFirst(['value' => $plaintext])->hash;
        $this->cache[$hash] = $plaintext;

        return $hash;
    }

    #[Override]
    public function retrieve(string $hash): ?string
    {
        return $this->cache[$hash] ??= ShreddingValue::query()->whereKey($hash)->value('value');
    }
}
