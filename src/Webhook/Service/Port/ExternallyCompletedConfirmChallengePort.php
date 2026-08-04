<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\ConfirmChallengeOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\ConfirmChallengePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\ConfirmChallengeRequest;

/**
 * {@see ConfirmChallengePort} for the webhook flow: the gateway raised the
 * authentication against a payment it had already opened, and settled it once the
 * cardholder was through — the webhook is the announcement — so the port
 * satisfies the domain contract without an outbound API call.
 *
 * Which is why confirming a challenge is not a
 * {@see \Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort} call: on this
 * path there is nothing to create. An implementation that does place a payment
 * would be one serving a challenge raised on our side, where the payment has been
 * held back — and every challenge there is so far is the gateway's, so this is the
 * only implementation.
 *
 * No challenge, because the one just resolved was the last: a gateway wanting
 * another would say so in the webhook rather than through this port. No
 * conversion figure either, which is the same gap its capture counterpart has
 * here.
 */
final class ExternallyCompletedConfirmChallengePort implements ConfirmChallengePort
{
    public function confirm(ConfirmChallengeRequest $request): ConfirmChallengeOutcome
    {
        // gateway already settled it — nothing to do here
        return new ConfirmChallengeOutcome();
    }
}
