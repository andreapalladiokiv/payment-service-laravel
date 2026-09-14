<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Command;

use DateTimeImmutable;
use Override;
use Techork\PaymentService\Domain\Dispute\Command\ChangeDisputeDeadlineCommand;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;

/**
 * A delivery restating the window the provider gave the case.
 *
 * `recordedAt` is the fallback for a delivery that carries no key — the aggregate records the
 * signal's own moment when there is one and this otherwise — so a webhook passes both, and they are
 * the same instant for the deliveries this recorder sees. It is not dead weight: the interface exists
 * for callers whose statement is ours rather than a provider's, and those have no signal to date it
 * by.
 */
final readonly class ChangeDisputeDeadline implements ChangeDisputeDeadlineCommand
{
    public function __construct(
        private DisputeId $disputeId,
        private DateTimeImmutable $deadlineAt,
        private ?DisputeSignal $signal,
        private DateTimeImmutable $recordedAt,
    ) {}

    #[Override]
    public function disputeId(): DisputeId
    {
        return $this->disputeId;
    }

    #[Override]
    public function deadlineAt(): DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    #[Override]
    public function signal(): ?DisputeSignal
    {
        return $this->signal;
    }

    #[Override]
    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
