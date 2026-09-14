<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
use RuntimeException;
use Techork\PaymentService\Domain\Dispute\Port\AcceptDisputePort;
use Techork\PaymentService\Domain\Dispute\Port\AcceptOutcome;
use Techork\PaymentService\Domain\Dispute\Port\Request\AcceptDisputeRequest;
use Techork\PaymentService\Gateway\Command\DisputeConcessionCommand;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Role\ConcedesDisputes;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see AcceptDisputePort} backed by a gateway. Concedes the case and reports what the provider did.
 *
 * ## Irreversible, and where the confirmation is not
 *
 * The call it makes cannot be taken back: the case is lost and the disputed sum is gone. Requiring
 * an operator to confirm before it is dispatched belongs to the application (A2) — this class holds
 * no policy and no screen, and prompting is not something an adapter can do. What it owes in
 * exchange is that a successful return means the case really moved, and that is why it reports
 * {@see AcceptOutcome::acceptedInFull()} only after the driver has refused to answer success on
 * anything but a closed case. A caller of this method is a caller that has already confirmed.
 *
 * ## The case's reference is resolved here, from our own table
 *
 * The provider's calls are addressed by its own reference for the case, and that reference lives
 * nowhere but `gateway_references` — so this adapter hands the command one it read by our dispute
 * aggregate id, the name the request carries. A missing row is our own bookkeeping rather than the
 * provider's answer: there is nothing to concede because we never recorded which case the provider
 * knows, and it throws a {@see RuntimeException} for that reason instead of arriving at the caller
 * as a refusal. The row is written when the case is observed, by the recorder implementation (A0).
 *
 * ## Depends on the one method it uses
 *
 * {@see ConcedesDisputes} rather than the whole gateway, for the reason {@see CaptureAdapter} gives:
 * one method is what this class uses, and one method is what a reader should have to check.
 *
 * ## The partial amount is passed through, not resolved here
 *
 * `$request->partialAmount` goes straight into the command. An adapter that refused it here would
 * bury the reason in the wrong layer, and one that dropped it and closed the whole case would give
 * up the rest of the disputed sum on an irreversible call — so it neither refuses nor rounds: the
 * driver decides whether its provider can honour a partial, and Stripe cannot, which is why a
 * non-null amount reaches Stripe's driver as an
 * {@see \Techork\PaymentService\Gateway\Exception\UnsupportedByGateway}-marked refusal and
 * propagates as a wiring error. Nuvei's `PARTIAL` is the provider that will answer where Stripe
 * refuses, and it implements the same role.
 *
 * ## The three outcomes, and the one this provider cannot produce
 *
 * `alreadyClosed()` — the provider had resolved the case before our call — is not reachable here,
 * and it is not invented either: Stripe answers a close on a case it no longer considers open with
 * an error rather than with a case, so that arrives as {@see DisputeCallRefused}. Recording an
 * already-decided case as "our call closed it" would book a concession at a moment we did not make
 * one, which is the mistake the outcome type exists to prevent; recording it as a failure is the
 * honest reading of the only answer the provider gives.
 */
final readonly class AcceptDisputeAdapter implements AcceptDisputePort
{
    public function __construct(
        private ConcedesDisputes $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
    ) {}

    #[Override]
    public function accept(AcceptDisputeRequest $request): AcceptOutcome
    {
        $disputeId = $request->disputeId->toString();

        $reference = $this->transactionRepository->findForDispute($disputeId)
            // Our own bookkeeping, not the provider's answer: there is nothing to concede
            // because we never recorded which case the provider knows. Never a refusal.
            ?? throw new RuntimeException("No gateway transaction reference recorded for dispute '$disputeId'.");

        $result = $this->gateway->concede(new DisputeConcessionCommand(
            gatewayId: $this->gatewayId,
            disputeReference: $reference,
            partialAmount: $request->partialAmount,
            // The case is closed at most once, so the key needs no digest: a repeated call for the
            // same case is the same intent, and a second close is the one thing this must not
            // perform twice.
            clientUniqueId: $reference.':close',
        ));

        if (! $result->success) {
            throw DisputeCallRefused::concession($reference, $result->message);
        }

        return AcceptOutcome::acceptedInFull();
    }
}
