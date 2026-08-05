<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use Techork\PaymentService\Laravel\Shredding\PiiStore;

/**
 * The `#[Pii]` side-store, in memory, for tests that persist real events.
 *
 * Every event carrying a {@see \Techork\PaymentService\Common\ValueObject\BillingAddress}
 * reaches {@see \Techork\PaymentService\Laravel\Serializer\PiiAwareObjectNormalizer} on the
 * way to the stream, so a store is not optional for an event-sourcing test — without one
 * there is nothing to swap the name and address line for, and nothing to resolve them back
 * from on read. `EloquentPiiStore` would drag `shredding_values` and a model into a test
 * about the event stream; this keeps the substitution real while leaving the subject alone.
 *
 * `forget()` is deliberately absent: {@see PiiStore} does not declare it, and a test that
 * wants to observe the shredded-stub path deletes from `$byHash` directly.
 */
final class EventStreamPiiStore implements PiiStore
{
    /** @var array<string, string> */
    public array $byHash = [];

    public function store(string $plaintext): string
    {
        $hash = hash('sha256', $plaintext);
        $this->byHash[$hash] = $plaintext;

        return $hash;
    }

    public function retrieve(string $hash): ?string
    {
        return $this->byHash[$hash] ?? null;
    }
}
