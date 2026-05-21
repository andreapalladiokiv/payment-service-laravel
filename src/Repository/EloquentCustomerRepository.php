<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Laravel\Models\GatewayCustomer;
use Techork\PaymentService\Laravel\Models\GatewayReference;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

final class EloquentCustomerRepository implements CustomerRepository, PaymentInstrumentVisitor
{
    public function findByInstrument(GatewayId $gatewayId, PaymentInstrument $instrument): ?string
    {
        $id = $this->resolveId($instrument);

        if ($id === null) {
            return null;
        }

        return GatewayCustomer::query()
            ->join('gateway_reference_customer', 'gateway_customers.id', '=', 'gateway_reference_customer.gateway_customer_id')
            ->join('gateway_references', 'gateway_reference_customer.gateway_reference_id', '=', 'gateway_references.id')
            ->where('gateway_customers.gateway_id', $gatewayId->toString())
            ->where('gateway_references.referenceable_type', $instrument::type())
            ->where('gateway_references.referenceable_id', $id)
            ->value('gateway_customers.customer_reference');
    }

    public function saveAndAttach(GatewayId $gatewayId, PaymentInstrument $instrument, string $customerReference): void
    {
        $id = $this->resolveId($instrument);

        if ($id === null) {
            return;
        }

        $reference = GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', $instrument::type())
            ->where('referenceable_id', $id)
            ->first();

        if ($reference === null) {
            return;
        }

        $customer = GatewayCustomer::unguarded(fn () => GatewayCustomer::query()->firstOrCreate([
            'gateway_id' => $gatewayId->toString(),
            'customer_reference' => $customerReference,
        ]));

        $reference->customers()->syncWithoutDetaching([$customer->id]);
    }

    private function resolveId(PaymentInstrument $instrument): ?UuidValueObject
    {
        return $instrument->accept($this);
    }

    public function visitCreditCard(CreditCard $card): null
    {
        return null;
    }

    public function visitCash(Cash $cash): null
    {
        return null;
    }

    public function visitToken(Token $token): TokenId
    {
        return $token->id;
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): PaymentMethodId
    {
        return $paymentMethod->id;
    }

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new \RuntimeException('Hosted-payment instruments are not supported in this context.');
    }
}
