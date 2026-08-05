<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Command;

use Money\Money;
use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Command\CreateRefundCommand;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;

final readonly class CreateRefund implements CreateRefundCommand
{
    public function __construct(
        private RefundId $refundId,
        private Money $amount,
    ) {}

    #[Override]
    public function refundId(): RefundId
    {
        return $this->refundId;
    }

    #[Override]
    public function amount(): Money
    {
        return $this->amount;
    }

    #[Override]
    public function retryInstrument(): ?PaymentInstrument
    {
        // Webhook-driven refunds always originate at the gateway, where the
        // original transaction is the only payment source involved. There is
        // no retry-card concept in webhook ingestion.
        return null;
    }
}
