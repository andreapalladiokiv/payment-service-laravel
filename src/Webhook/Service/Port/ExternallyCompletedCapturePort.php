<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;

/**
 * {@see CapturePort} for the webhook flow: the gateway has already captured
 * the funds (the webhook is the announcement), so the port satisfies the
 * domain contract without an outbound API call.
 */
final class ExternallyCompletedCapturePort implements CapturePort
{
    public function capture(CaptureRequest $request): void
    {
        // gateway already captured — nothing to do here
    }
}
