<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\RefundPort;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;

/**
 * {@see RefundPort} for the webhook flow. The gateway has already decided
 * the refund's outcome — the webhook payload tells us which. The port
 * "replays" that decision back to the aggregate:
 *   - {@see successful()} returns void → aggregate records `RefundProcessed`
 *   - {@see declined()} throws {@see GatewayDeclinedException} → aggregate records `RefundFailed`
 */
final readonly class ExternallyCompletedRefundPort implements RefundPort
{
    private function __construct(
        private ?string $declineReason,
    ) {}

    public static function successful(): self
    {
        return new self(null);
    }

    public static function declined(string $reason): self
    {
        return new self($reason);
    }

    public function refund(RefundRequest $request): void
    {
        if ($this->declineReason !== null) {
            throw new GatewayDeclinedException($this->declineReason);
        }
    }
}
