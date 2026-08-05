<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Command;

use Override;
use Techork\PaymentService\Domain\PaymentIntent\Command\CancelPaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

final readonly class CancelPaymentIntent implements CancelPaymentIntentCommand
{
    public function __construct(
        private PaymentIntentId $paymentIntentId,
        private string $reason,
    ) {}

    #[Override]
    public function paymentIntentId(): PaymentIntentId
    {
        return $this->paymentIntentId;
    }

    #[Override]
    public function reason(): string
    {
        return $this->reason;
    }
}
