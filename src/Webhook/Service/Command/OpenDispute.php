<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Command;

use DateTimeImmutable;
use Money\Money;
use Override;
use Techork\PaymentService\Domain\Dispute\Command\OpenDisputeCommand;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * The opening of a case, as a webhook delivery states it.
 *
 * Every field is required except the two the provider may legitimately not have stated — the deadline
 * (`hasResponseDeadline()` is a question a case can answer "not yet") and the provider's own code for
 * the stage, which only the providers that state one supply. The amount is not among the exceptions:
 * a case with no amount cannot be opened, so the recorder refuses the delivery rather than inventing
 * a zero, and the refusal is in {@see \Techork\PaymentService\Laravel\Webhook\Service\EloquentDisputeRecorder},
 * not here — a command that silently defaulted one would move the same decision somewhere nothing
 * can refuse it.
 */
final readonly class OpenDispute implements OpenDisputeCommand
{
    public function __construct(
        private DisputeId $disputeId,
        private PaymentIntentId $paymentIntentId,
        private DisputeStage $stage,
        private DisputeStatus $status,
        private DisputeReason $reason,
        private Money $disputedAmount,
        private ?DateTimeImmutable $deadlineAt,
        private ?string $providerCode,
        private DisputeSignal $signal,
    ) {}

    #[Override]
    public function disputeId(): DisputeId
    {
        return $this->disputeId;
    }

    #[Override]
    public function paymentIntentId(): PaymentIntentId
    {
        return $this->paymentIntentId;
    }

    #[Override]
    public function stage(): DisputeStage
    {
        return $this->stage;
    }

    #[Override]
    public function status(): DisputeStatus
    {
        return $this->status;
    }

    #[Override]
    public function reason(): DisputeReason
    {
        return $this->reason;
    }

    #[Override]
    public function disputedAmount(): Money
    {
        return $this->disputedAmount;
    }

    #[Override]
    public function deadlineAt(): ?DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    #[Override]
    public function providerCode(): ?string
    {
        return $this->providerCode;
    }

    #[Override]
    public function signal(): DisputeSignal
    {
        return $this->signal;
    }
}
