<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Command;

use Override;
use Techork\PaymentService\Domain\Dispute\Command\ChangeDisputeStatusCommand;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;

/**
 * A delivery moving the case along the status axis.
 *
 * No `providerCode`: the status is the whole fact, and the aggregate records which delivery stated it
 * through the signal. `DisputeStatus::Accepted` never arrives this way — it is ours to write and
 * reaches the aggregate only through `accept()` — so a delivery reporting it is refused by the
 * aggregate rather than accepted here.
 */
final readonly class ChangeDisputeStatus implements ChangeDisputeStatusCommand
{
    public function __construct(
        private DisputeId $disputeId,
        private DisputeStatus $status,
        private DisputeSignal $signal,
    ) {}

    #[Override]
    public function disputeId(): DisputeId
    {
        return $this->disputeId;
    }

    #[Override]
    public function status(): DisputeStatus
    {
        return $this->status;
    }

    #[Override]
    public function signal(): DisputeSignal
    {
        return $this->signal;
    }
}
