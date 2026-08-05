<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Shredding;

use Override;
use Techork\PaymentService\Laravel\Models\ShreddingValue;

final class EloquentPiiStore implements PiiStore
{
    /**
     * Only values that were actually resolved, never misses.
     *
     * Memoising a miss looked harmless and lost data. `retrieve()` used `??=`, which ASSIGNS
     * null when no row matches, so the hash gained a key in the cache; `store()` then gated on
     * `array_key_exists` and returned that same hash without writing anything. A retrieve for
     * an absent hash followed by a store of its plaintext therefore reported success, wrote no
     * row, and left the value unrecoverable — from the same instance and from every later one.
     * The caller writes the returned hash into the event payload, so the field reads as shredded
     * for good.
     *
     * The sequence is reachable: a value erased under GDPR retrieves as null, and the next
     * event carrying the same plaintext stores it again. A miss is also not a stable answer,
     * precisely because a `store()` in the same request can make it resolvable.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    #[Override]
    public function store(string $plaintext): string
    {
        $hash = hash('sha256', $plaintext);

        if (isset($this->cache[$hash])) {
            return $hash;
        }

        $hash = ShreddingValue::query()->createOrFirst(['value' => $plaintext])->hash;
        $this->cache[$hash] = $plaintext;

        return $hash;
    }

    #[Override]
    public function retrieve(string $hash): ?string
    {
        if (isset($this->cache[$hash])) {
            return $this->cache[$hash];
        }

        $value = ShreddingValue::query()->whereKey($hash)->value('value');

        if ($value === null) {
            return null;
        }

        return $this->cache[$hash] = $value;
    }
}
