<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\CancelPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CancelRequest;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see CancelPort} backed by {@see PaymentGatewayInterface}. Voids /
 * cancels a held authorization. Gateway refusal becomes a
 * {@see GatewayDeclinedException}; the aggregate's `cancel()` lets it
 * propagate so the caller can decide whether to retry or accept that the
 * auth will simply expire.
 */
final readonly class OmnipayCancelPort implements CancelPort
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private GatewayId $gatewayId,
    ) {}

    public function cancel(CancelRequest $request): void
    {
        $paymentIntentId = $request->paymentIntentId->toString();

        $result = $this->gateway->cancel(
            $this->gatewayId,
            $paymentIntentId,
            $paymentIntentId.':cancel',
        );

        if (!$result->success) {
            throw new GatewayDeclinedException($result->message ?? 'Gateway declined the cancellation');
        }
    }
}
