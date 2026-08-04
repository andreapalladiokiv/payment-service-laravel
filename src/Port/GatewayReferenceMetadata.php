<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;

/**
 * Metadata keys the ports in this namespace agree on when they write through
 * {@see GatewayTransactionRepository}.
 *
 * Here rather than on that interface, because the interface's job is to store a
 * reference and an opaque bag of "gateway-specific transaction attributes" — it has no
 * business knowing that two of its callers have a convention about what goes inside
 * the bag. Publishing the key there put a storage detail in a contract; keeping it
 * beside the ports that share it keeps the agreement visible to exactly the code that
 * has to honour it, and greppable rather than spelled out at each site.
 */
final class GatewayReferenceMetadata
{
    /**
     * The reference the gateway returned when the payment intent was OPENED.
     *
     * `reference` cannot answer that later on, because it overwrites on transition: an
     * authorization is followed by a capture, and the row then holds the settle
     * reference. Both are wanted — Nuvei's settle expects the authorization's
     * transactionId, its refund expects the settle's — so the two live side by side
     * instead of one winning.
     *
     * The reader that needs THIS one is the rebilling anchor: "If utilizing a
     * auth/settle transaction flow, then relatedTransactionId should reference
     * transactionId from the original authorization flow (not from the settle flow)."
     */
    public const string OPENING_REFERENCE = 'opening_transaction_reference';
}
