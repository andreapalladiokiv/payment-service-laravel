<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Command;

use Override;
use Techork\PaymentService\Domain\Dispute\Command\ChangeDisputeStageCommand;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;

/**
 * A delivery placing the case in a stage — or restating the one it is already in, which the aggregate
 * records nothing for.
 *
 * `providerCode` is where the provider's own cycle code goes, and it is the reason this command
 * carries one at all: a stage history cannot tell a second visit to a stage from a redelivery without
 * it. Null is the honest answer for a provider that states no code of its own, and the aggregate
 * carries it as a null rather than substituting the stage's name.
 */
final readonly class ChangeDisputeStage implements ChangeDisputeStageCommand
{
    public function __construct(
        private DisputeId $disputeId,
        private DisputeStage $stage,
        private DisputeSignal $signal,
        private ?string $providerCode,
    ) {}

    #[Override]
    public function disputeId(): DisputeId
    {
        return $this->disputeId;
    }

    #[Override]
    public function stage(): DisputeStage
    {
        return $this->stage;
    }

    #[Override]
    public function signal(): DisputeSignal
    {
        return $this->signal;
    }

    #[Override]
    public function providerCode(): ?string
    {
        return $this->providerCode;
    }
}
