<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummaryExtractor;
use Techork\PaymentService\Common\ValueObject\Risk\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Domain\PaymentIntent\ChallengeFailureReasonExtractor;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreateOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\RiskAssessmentRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskDecisionPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskPhase;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see CreatePort} decorator that screens an interactive card payment through
 * the fraud check rules ({@see RiskDecisionPort}) before it reaches the gateway.
 *
 * When the rules require a step-up and no successful 3DS authentication is
 * present yet, it short-circuits with a {@see ThreeDSChallenge} — the aggregate
 * parks at RequiresAction and the client completes 3DS (via the 3dsintegrator)
 * before retrying with the result. Otherwise it delegates to the wrapped port
 * unchanged. Fraud screening lives here, in the composition layer that already
 * has both the gateway and the fraud checker, so the domain stays unaware of it.
 *
 * The connection signals and the registration-phase `fraudReference` are
 * per-payment, so they are injected at construction (the composition root builds
 * this decorator per payment) rather than threaded through the domain command.
 */
final readonly class FraudScreeningCreatePort implements CreatePort
{
    public function __construct(
        private CreatePort $inner,
        private RiskDecisionPort $riskDecision,
        private GatewayId $gatewayId,
        private ConnectionContext $connection,
        private ?string $fraudReference = null,
    ) {}

    public function create(CreateRequest $request): CreateOutcome
    {
        // Fraud screening and 3DS step-up apply only to a cardholder-initiated
        // payment; a merchant-initiated one (recurring / unscheduled) has no
        // cardholder to complete a challenge, so never force it.
        if ($request->initiation->isMerchantInitiated()) {
            return $this->inner->create($request);
        }

        // A completed 3DS authentication already claims the liability shift, so
        // a forced step-up would be redundant — let the charge proceed with it.
        if ($this->hasSuccessfulThreeDS($request->challengeResult)) {
            return $this->inner->create($request);
        }

        $card = CardSummaryExtractor::from($request->instrument);

        // Nothing to screen for a non-card instrument.
        if ($card === null) {
            return $this->inner->create($request);
        }

        $outcome = $this->riskDecision->decide(new RiskAssessmentRequest(
            amount: $request->amount,
            card: $card,
            billing: $request->billingAddress,
            connection: $this->connection,
            phase: RiskPhase::Authorization,
            paymentIntentId: $request->paymentIntentId,
            fraudReference: $this->fraudReference,
            gatewayId: $this->gatewayId->toString(),
        ));

        if ($outcome->requiresThreeDS()) {
            return new CreateOutcome(challenge: new ThreeDSChallenge(
                transactionId: $request->paymentIntentId->toString(),
            ));
        }

        return $this->inner->create($request);
    }

    private function hasSuccessfulThreeDS(?ChallengeResult $result): bool
    {
        return $result instanceof ThreeDSResult
            && $result->accept(new ChallengeFailureReasonExtractor) === null;
    }
}
