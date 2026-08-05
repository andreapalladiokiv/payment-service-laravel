<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
use RuntimeException;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\RefundPort;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see RefundPort} backed by {@see PaymentGatewayInterface}. Issues a
 * refund against the parent PaymentIntent's gateway transaction and
 * persists the refund's gateway reference. Gateway refusal becomes
 * {@see GatewayDeclinedException} so the aggregate records `RefundFailed`.
 */
final readonly class OmnipayRefundPort implements RefundPort
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
    ) {}

    #[Override]
    public function refund(RefundRequest $request): void
    {
        $paymentIntentId = $request->paymentIntentId->toString();
        $refundId = $request->refundId->toString();

        $transactionReference = $this->transactionRepository->findForPaymentIntent($paymentIntentId)
            ?? throw new RuntimeException("No gateway transaction reference recorded for payment intent '$paymentIntentId'.");

        $result = $this->gateway->refund(
            $this->gatewayId,
            $transactionReference,
            $request->amount,
            $refundId,
            $request->retryInstrument,
        );

        if ($result->reference !== null) {
            $this->transactionRepository->saveForRefund($this->gatewayId, $refundId, $result->reference);
        }

        if (!$result->success) {
            throw new GatewayDeclinedException($result->message ?? 'Gateway declined the refund');
        }
    }
}
