<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreateOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see CreatePort} for a payment that belongs to a stored-credential series — a
 * subscription's first charge, or any of its renewals.
 *
 * The same interface as {@see OmnipayCreatePort} and a different implementation,
 * which is the whole point: the SCENARIO is chosen by which one the caller is given,
 * not by a field the aggregate has to branch on. Nothing else can choose it. A
 * subscription opened by a present cardholder is cardholder-initiated with nothing
 * before it, exactly like a standalone checkout, so no combination of the request's
 * fields separates the two — only the knowledge that this payment is a
 * subscription's, which the caller has and the aggregate does not.
 *
 * What follows from being in a series:
 *
 *  - the acquirer is told the position. Absent genesis means THIS payment opens the
 *    series; present means it continues one, and the reference names the opener.
 *    Outside a series that same absence would be ambiguous, which is why the
 *    distinction lives in the choice of port.
 *  - authorize-only, no capture-method branch.
 *    {@see \Techork\PaymentService\Domain\Subscription\SubscriptionAggregate::activate}
 *    requires an `Authorized` intent and captures it, and that split is what makes
 *    "one payment intent activates at most one subscription" true without a rule of
 *    its own. An `Immediate` request here is a contradiction rather than a variant,
 *    and is refused as one.
 *
 * The genesis crosses from domain identity to acquirer reference here and nowhere
 * earlier, through the same lookup capture, cancel and refund already use — so the
 * promise on `CreatePaymentIntentCommand::gatewayId()`, that the domain "learns
 * nothing about the gateway itself", still holds.
 */
final readonly class OmnipayStoredCredentialCreatePort implements CreatePort
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
    ) {}

    public function create(CreateRequest $request): CreateOutcome
    {
        if ($request->captureMethod === CaptureMethod::Immediate) {
            // Not a decline: the caller asked for a combination the subscription
            // domain cannot consume, and an acquirer would happily take the money
            // for it. Refuse before spending the call, and refuse as a wiring error
            // so it cannot be read as an issuer saying no.
            throw UnsupportedOperation::forGateway(
                'stored-credential series',
                'authorizeStoredCredential',
                'a payment in a series is authorized and captured separately, so Immediate capture cannot be used.',
            );
        }

        $clientUniqueId = $request->paymentIntentId->toString();

        // Null resolves to null, and that is meaningful here rather than a gap: this
        // payment opens the series. A genesis that was named but whose reference was
        // never stored also lands here, and passing nothing beats passing an id the
        // acquirer has never seen — which it would refuse, as a decline.
        $genesisReference = $request->genesisPaymentIntentId === null
            ? null
            : $this->transactionRepository->findForPaymentIntent($request->genesisPaymentIntentId->toString());

        $result = $this->gateway->authorizeStoredCredential(
            $this->gatewayId,
            $request->instrument,
            $request->amount,
            $request->initiation,
            $genesisReference,
            $clientUniqueId,
            $request->billingAddress,
            $request->challengeResult instanceof ThreeDSResult ? $request->challengeResult : null,
        );

        if ($result->reference !== null) {
            $this->transactionRepository->saveForPaymentIntent($this->gatewayId, $clientUniqueId, $result->reference, $result->metadata);
        }

        if ($result->challenge !== null) {
            return new CreateOutcome(challenge: $result->challenge);
        }

        if (! $result->success) {
            throw new GatewayDeclinedException($result->message ?? 'Gateway declined the transaction');
        }

        // No convertedAmount: an authorization holds funds without settling, so no FX
        // has happened yet. It surfaces on capture, as it does on the ordinary
        // authorize path.
        return new CreateOutcome;
    }
}
