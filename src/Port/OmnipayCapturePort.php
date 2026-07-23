<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\CaptureOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see CapturePort} backed by {@see PaymentGatewayInterface}. Captures an
 * existing authorization at the gateway and persists the capture reference;
 * gateway refusal becomes {@see GatewayDeclinedException}.
 */
final readonly class OmnipayCapturePort implements CapturePort
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
    ) {}

    public function capture(CaptureRequest $request): CaptureOutcome
    {
        $paymentIntentId = $request->paymentIntentId->toString();

        $result = $this->gateway->capture(
            $this->gatewayId,
            $paymentIntentId,
            $request->amount,
            $paymentIntentId.':capture',
        );

        if ($result->reference !== null) {
            $this->transactionRepository->saveForPaymentIntent($this->gatewayId, $paymentIntentId, $result->reference, $result->metadata);
        }

        if (!$result->success) {
            throw new GatewayDeclinedException($result->message ?? 'Gateway declined the capture');
        }

        return new CaptureOutcome($result->convertedAmount);
    }
}
