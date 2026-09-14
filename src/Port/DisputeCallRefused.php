<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use RuntimeException;

/**
 * The provider was asked and would not do it — and no outcome term in this domain says so.
 *
 * ## Why this is a type rather than a bare `RuntimeException`
 *
 * The two submission ports carry outcomes for the case the provider *moved*, and nothing at all
 * for the case it refused: {@see \Techork\PaymentService\Domain\Dispute\Port\SubmissionOutcome}
 * reports which of `DRAFT` / `UPLOAD_PENDING` / `SUBMITTED` our own side reached — a fact about a
 * call that happened — and {@see \Techork\PaymentService\Domain\Dispute\Port\AcceptOutcome}'s
 * `alreadyClosed()` is reachable only when the provider answers with a case, which Stripe does not
 * do for a close it rejects. So a refusal arrives as an exception, and it has to be catchable by
 * type: it is a fact an operator is shown ("the provider would not take this evidence"), not a
 * crash, and an application that catches it to explain that has to tell it apart from a bug in our
 * own wiring.
 *
 * ## Why it lives here, and what is missing above
 *
 * The dispute domain has no refusal vocabulary of its own yet — there is no
 * `DisputeRefusedException` beside `AcceptOutcome`, and
 * {@see \Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException} cannot be
 * borrowed: an aggregate owns its ports, and a payment intent's decline says nothing about a case
 * a network is asking about. The honest placement until that vocabulary exists is the layer that
 * talks to the provider, which is this package — the same reasoning, and the same directory, as
 * {@see PaymentAlreadyPlaced}. It carries no `ErrorCode` for the same reason it is not an outcome:
 * the codes in this tree name transport-shaped failures, and none of them means "the network is no
 * longer asking".
 *
 * ## What must not arrive here
 *
 * A provider with no such call at all. ConnexPay's dispute API is read-only and a Nuvei/Stripe
 * partial acceptance is refused by the driver with an
 * {@see \Techork\PaymentService\Gateway\Exception\UnsupportedByGateway}-marked exception, which is
 * a wiring error and propagates as one rather than being dressed up as a provider that said no.
 */
final class DisputeCallRefused extends RuntimeException
{
    /**
     * The provider would not take the evidence, so nothing was filed and nothing staged.
     *
     * @param  ?string  $reason  the provider's own message, verbatim. It names the field or the
     *   state it objected to, and a paraphrase would lose the only explanation an operator gets.
     */
    public static function submission(string $disputeReference, ?string $reason): self
    {
        return new self(sprintf(
            'The provider refused the evidence for dispute "%s", so nothing was staged and nothing '
            . 'was filed. It said: %s',
            $disputeReference,
            $reason ?? 'no reason',
        ));
    }

    /**
     * The provider would not close the case, so nothing was conceded.
     *
     * The distinction this message has to make: the case is not lost, and it is not conceded
     * either — a caller that read this as "we accepted" would record a decision nobody took. A
     * case the provider reports as already closed arrives here too, because Stripe answers that
     * with an error rather than with a status.
     */
    public static function concession(string $disputeReference, ?string $reason): self
    {
        return new self(sprintf(
            'The provider refused to close dispute "%s", so nothing was conceded and the case is '
            . 'not lost. It said: %s',
            $disputeReference,
            $reason ?? 'no reason',
        ));
    }
}
