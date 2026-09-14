<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use LogicException;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * `gateway_references`, in memory: the table a dispute's provider reference is read back from.
 *
 * ## Why the port tests need this at all
 *
 * A dispute no longer carries the provider's name for the case — the aggregate, the command and the
 * port requests all speak our `DisputeId` and nothing else, and `gateway_references` is now the only
 * place the two names are held side by side. Each of the four Laravel dispute adapters therefore
 * reads a row before it can address the provider, and a test that handed one no table at all would
 * be exercising a branch nobody reaches. This is that table, and nothing more.
 *
 * ## Why a fake rather than the Eloquent repository
 *
 * The real implementation is pinned against a real in-memory SQLite Capsule in
 * {@see \Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository}'s own
 * suite — schema, morph type, upsert and all — and two suites booting one process-wide connection
 * to cover the same table would be a second copy of that harness with its own drift. What these
 * files are about is the adapter's translation across the seam: the request arrives with our id,
 * the command leaves with the provider's reference, and the assertion is on the command. So the
 * table here is the smallest thing that answers a read: a map.
 *
 * ## Where it is faithful, and where it refuses to be
 *
 * Faithful on the two points the adapters depend on:
 *
 *  - **the key is our aggregate id, and the gateway is not part of it.** This mirrors the real
 *    repository, which looks a row up by morph type and `referenceable_id` alone — an aggregate id
 *    is ours and globally unique, so the gateway plays no part in the lookup there either;
 *  - **the write is upsert-shaped**, one row per (gateway, dispute): the reference written last is
 *    the one read back.
 *
 * Refusing on everything else, deliberately. A payment-intent or refund read or write throws rather
 * than answering null: a dispute port that reached for one of those is asking the wrong question,
 * and a silent null would let it pass as "no row" instead of showing up as the wiring error it is.
 * The precedent is the aggregate repository fake in the action-set tests, which refuses for the same
 * reason.
 */
final class DisputeReferenceTable implements GatewayTransactionRepository
{
    /** @var array<string, string> our dispute id => the provider's reference for that case */
    private array $disputes = [];

    /** @var list<array{gatewayId: string, disputeId: string, reference: string}> every dispute write, in order */
    public array $disputeWrites = [];

    public function findForDispute(string $disputeId): ?string
    {
        return $this->disputes[$disputeId] ?? null;
    }

    public function saveForDispute(GatewayId $gatewayId, string $disputeId, string $reference): void
    {
        $this->disputes[$disputeId] = $reference;
        $this->disputeWrites[] = [
            'gatewayId' => $gatewayId->toString(),
            'disputeId' => $disputeId,
            'reference' => $reference,
        ];
    }

    public function findForPaymentIntent(string $paymentIntentId): ?string
    {
        throw new LogicException(
            "A dispute port asked this table for the reference of payment intent '{$paymentIntentId}'. "
            .'Disputes are keyed by our aggregate id, and reaching for a payment here is a wiring '
            .'error rather than an empty answer.',
        );
    }

    public function saveForPaymentIntent(GatewayId $gatewayId, string $paymentIntentId, string $reference, array $metadata = []): void
    {
        throw new LogicException(
            "A dispute port wrote a payment-intent reference through this table ('{$paymentIntentId}'). "
            .'Nothing in a dispute\'s path writes one.',
        );
    }

    public function findMetadataForPaymentIntent(string $paymentIntentId): array
    {
        throw new LogicException('A dispute port asked for payment-intent metadata through this table.');
    }

    public function findForRefund(string $refundId): ?string
    {
        throw new LogicException("A dispute port asked this table for the reference of refund '{$refundId}'.");
    }

    public function saveForRefund(GatewayId $gatewayId, string $refundId, string $reference): void
    {
        throw new LogicException("A dispute port wrote a refund reference through this table ('{$refundId}').");
    }
}
