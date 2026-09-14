<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
use RuntimeException;
use Techork\PaymentService\Domain\Dispute\Port\Request\SubmitEvidenceRequest;
use Techork\PaymentService\Domain\Dispute\Port\SubmissionOutcome;
use Techork\PaymentService\Domain\Dispute\Port\SubmitEvidencePort;
use Techork\PaymentService\Domain\Dispute\SubmissionState;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceFormat;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceItem;
use Techork\PaymentService\Gateway\Command\DisputeEvidenceCommand;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Role\SubmitsDisputeEvidence;
use Techork\PaymentService\Gateway\ValueObject\DisputeEvidenceItem;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see SubmitEvidencePort} backed by a gateway. Files — or stages — a case's evidence and reports
 * the submission state our own side reached.
 *
 * ## What crosses, and in which direction
 *
 * The facts travel outward only. {@see EvidenceItem} carries the domain's own vocabulary — the
 * enum value `proof_of_delivery_or_service`, the closed {@see EvidenceFormat} — and
 * {@see DisputeEvidenceItem} is where it becomes a fact name and a MIME type, which is the shape a
 * provider's request is written in. Nothing maps back in: a provider field name is never parsed
 * for a fact, so a table that drifts fails a mapping instead of quietly answering a different
 * question.
 *
 * ## The case's reference is resolved here, from our own table
 *
 * The provider's calls are addressed by its own reference for the case, and that reference lives
 * nowhere but `gateway_references` — so the command is handed one this adapter read by our dispute
 * aggregate id, the name the request carries. A missing row throws a {@see RuntimeException}: it is
 * our own bookkeeping rather than the provider's answer, so nothing was filed and the case is not
 * where a refusal would say it is. The row is written when the case is observed, by the recorder
 * implementation (A0).
 *
 * ## The two calls, and the digest that keeps them apart
 *
 * Stripe stages with `submit: false` and sends with `submit: true`; the flag is the inversion of
 * the domain's `stageOnly`, and this is the only place either name appears. Which of the two a
 * call is also rides in the idempotency key, and so does a digest of the evidence itself — both
 * deliberately:
 *
 *  - **The step must be in the key.** A keyed-on-nothing-but-the-case call would make the staging
 *    and the send the *same* call to Stripe, whose idempotency cache lasts 24 hours: the second
 *    would be answered with the first's response and the response would never go out.
 *  - **The content must be in the key.** Keyed on the case and the step alone, a package corrected
 *    and re-staged the same day would be replayed as the previous staging — the same file ids —
 *    and the correction would never reach Stripe. Keyed on the bytes, an identical re-stage is
 *    answered from the cache (a genuine duplicate, no second upload) while a changed one is a
 *    different call.
 *
 * The items are digested **in the order the package holds them**, not sorted: the provider composes
 * several of our facts into one text field in that order, so two packages that differ only in
 * order are two different requests and must be two different keys.
 *
 * ## Failure, and what a caller has to catch
 *
 * A provider that refused the evidence throws {@see DisputeCallRefused} — nothing was filed, and
 * the case is exactly where it was. A provider with no submission at all is not that: it refuses
 * with an {@see \Techork\PaymentService\Gateway\Exception\UnsupportedByGateway}-marked exception
 * from the driver, which is a wiring error and propagates as one. Neither is a decline the
 * aggregate could record, which is why neither arrives as an outcome.
 */
final readonly class SubmitEvidenceAdapter implements SubmitEvidencePort
{
    public function __construct(
        private SubmitsDisputeEvidence $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
    ) {}

    #[Override]
    public function submit(SubmitEvidenceRequest $request): SubmissionOutcome
    {
        $disputeId = $request->disputeId->toString();

        $reference = $this->transactionRepository->findForDispute($disputeId)
            // Our own bookkeeping, not the provider's answer: there is nothing to file against
            // because we never recorded which case the provider knows. Never a refusal.
            ?? throw new RuntimeException("No gateway transaction reference recorded for dispute '$disputeId'.");

        $items = array_map(
            static fn (EvidenceItem $item): DisputeEvidenceItem => new DisputeEvidenceItem(
                type: $item->type->value,
                content: $item->content,
                mediaType: self::mediaType($item->format),
            ),
            $request->evidence->items(),
        );

        $result = $this->gateway->submitEvidence(new DisputeEvidenceCommand(
            gatewayId: $this->gatewayId,
            disputeReference: $reference,
            evidence: $items,
            submit: ! $request->stageOnly,
            clientUniqueId: self::idempotencyKey($reference, $request->stageOnly, $items),
        ));

        if (! $result->success) {
            throw DisputeCallRefused::submission($reference, $result->message);
        }

        // What our call did, not what the provider thinks of it: staging leaves the evidence on
        // the case and the case untouched, sending puts the response on its way to the network.
        // `CONFIRMED` is deliberately not reachable from here — no submission call ever writes it,
        // because it means the provider acknowledged, which is a signal and not a return value.
        return new SubmissionOutcome(
            $request->stageOnly ? SubmissionState::Draft : SubmissionState::Submitted,
        );
    }

    /**
     * The MIME type a document is uploaded under, or null when the content is text.
     *
     * Spelled out here rather than read off the enum, because the enum is the domain's closed set
     * of formats and this is the wire type — the two happen to have the same three members today
     * and mean different things, and a fourth format added there should fail here rather than
     * invent a part nobody can open.
     */
    private static function mediaType(EvidenceFormat $format): ?string
    {
        return match ($format) {
            EvidenceFormat::Text => null,
            EvidenceFormat::Pdf => 'application/pdf',
            EvidenceFormat::Jpeg => 'image/jpeg',
        };
    }

    /**
     * @param  list<DisputeEvidenceItem>  $items
     */
    private static function idempotencyKey(string $reference, bool $stageOnly, array $items): string
    {
        $fingerprints = array_map(
            static fn (DisputeEvidenceItem $item): string => implode('|', [
                $item->type,
                $item->mediaType ?? 'text',
                hash('sha256', $item->content),
            ]),
            $items,
        );

        return $reference.':'.($stageOnly ? 'stage' : 'submit').':'
            .hash('sha256', implode("\n", $fingerprints));
    }
}
