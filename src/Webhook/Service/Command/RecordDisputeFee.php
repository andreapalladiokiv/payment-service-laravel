<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Command;

use Override;
use Techork\PaymentService\Domain\Dispute\Command\RecordDisputeFeeCommand;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeFee;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;

/**
 * One fee a delivery states, already mapped onto the domain's own fee type.
 *
 * The mapping is the caller's and not this class's: a fee arrives from a provider as that provider's
 * own code, and turning it into a `FeeType` is the same translation the stage and the status go
 * through — see {@see \Techork\PaymentService\Laravel\Webhook\Service\EloquentDisputeRecorder}. What
 * is left here is a value object the aggregate can compare by identity, which is how it tells the
 * same fee told twice from two fees that happen to be equal.
 */
final readonly class RecordDisputeFee implements RecordDisputeFeeCommand
{
    public function __construct(
        private DisputeId $disputeId,
        private DisputeFee $fee,
        private DisputeSignal $signal,
    ) {}

    #[Override]
    public function disputeId(): DisputeId
    {
        return $this->disputeId;
    }

    #[Override]
    public function fee(): DisputeFee
    {
        return $this->fee;
    }

    #[Override]
    public function signal(): DisputeSignal
    {
        return $this->signal;
    }
}
