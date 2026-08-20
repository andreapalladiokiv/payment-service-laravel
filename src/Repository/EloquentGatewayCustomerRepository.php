<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Illuminate\Database\Eloquent\Model;
use Override;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Models\GatewayCustomer;

/**
 * `gateway_customers`, keyed on our customer rather than reached through an instrument's
 * reference.
 *
 * No join and no pivot, which is the whole difference from the `EloquentCustomerRepository` it
 * replaced: that one went customer → `gateway_reference_customer` → `gateway_references`, so it
 * could only answer for a customer that happened to own a stored instrument's reference. It is
 * deleted rather than deprecated, for the reason F2d gives — kept, its instrument-keyed lookup
 * stays reachable as a fallback for every caller that forgot to name a customer, and that
 * lookup is what registered provider-side customers under whatever address rode along with a
 * payment.
 *
 * An empty-string reference counts as missing on the way out, for the reason both gateway
 * resolvers already say in their own words: legacy rows exist where the reference was written
 * as `''`, and forwarding that produces a request the provider rejects.
 */
final readonly class EloquentGatewayCustomerRepository implements GatewayCustomerRepository
{
    /**
     * @param class-string<Model> $modelClass
     */
    public function __construct(private string $modelClass = GatewayCustomer::class)
    {
    }

    #[Override]
    public function find(GatewayId $gatewayId, string $customerId): ?string
    {
        $reference = $this->modelClass::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('customer_id', $customerId)
            ->value('customer_reference');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    #[Override]
    public function saveReference(GatewayId $gatewayId, string $customerId, string $reference): void
    {
        $this->modelClass::unguarded(fn() => $this->modelClass::query()->updateOrCreate(
            ['gateway_id' => $gatewayId, 'customer_id' => $customerId],
            ['customer_reference' => $reference],
        ));
    }
}
