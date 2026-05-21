<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\CancelPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CancelRequest;

/**
 * {@see CancelPort} for the webhook flow: the gateway has already voided the
 * auth (the webhook is the announcement), so the port satisfies the domain
 * contract without an outbound API call.
 */
final class ExternallyCompletedCancelPort implements CancelPort
{
    public function cancel(CancelRequest $request): void
    {
        // gateway already cancelled — nothing to do here
    }
}
