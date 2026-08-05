<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreateOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see CreatePort} backed by {@see PaymentGatewayInterface} (Omnipay-style
 * router). Selects `charge()` for Immediate captureMethod, `authorize()`
 * otherwise. Persists the gateway reference so subsequent capture / cancel /
 * refund can locate the transaction. Translates a non-success result into
 * {@see GatewayDeclinedException} so the aggregate records `PaymentIntentFailed`.
 */
final readonly class OmnipayCreatePort implements CreatePort
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
    ) {}

    #[Override]
    public function create(CreateRequest $request): CreateOutcome
    {
        $clientUniqueId = $request->paymentIntentId->toString();
        $threeDS = $request->challengeResult instanceof ThreeDSResult ? $request->challengeResult : null;

        $result = $request->captureMethod === CaptureMethod::Immediate
            ? $this->gateway->charge($this->gatewayId, $request->instrument, $request->amount, $clientUniqueId, $request->billingAddress, $threeDS, initiation: $request->initiation)
            : $this->gateway->authorize($this->gatewayId, $request->instrument, $request->amount, $clientUniqueId, $request->billingAddress, $threeDS, initiation: $request->initiation);

        if ($result->reference !== null) {
            $this->transactionRepository->saveForPaymentIntent($this->gatewayId, $clientUniqueId, $result->reference, $result->metadata);
        }

        if ($result->challenge !== null) {
            return new CreateOutcome(challenge: $result->challenge);
        }

        if (!$result->success) {
            throw new GatewayDeclinedException($result->message ?? 'Gateway declined the transaction');
        }

        return new CreateOutcome(convertedAmount: $result->convertedAmount);
    }
}
