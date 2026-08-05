<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Port;

use Override;
use Techork\PaymentService\Domain\PaymentIntent\Port\CaptureOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;

/**
 * {@see CapturePort} for the webhook flow: the gateway has already captured
 * the funds (the webhook is the announcement), so the port satisfies the
 * domain contract without an outbound API call. No conversion figure is
 * available on this path, so the outcome carries a null convertedAmount.
 */
final class ExternallyCompletedCapturePort implements CapturePort
{
    #[Override]
    public function capture(CaptureRequest $request): CaptureOutcome
    {
        // gateway already captured — nothing to do here
        return new CaptureOutcome();
    }
}
