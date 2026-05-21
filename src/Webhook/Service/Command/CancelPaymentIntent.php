<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Command;

use Techork\PaymentService\Domain\PaymentIntent\Command\CancelPaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

final readonly class CancelPaymentIntent implements CancelPaymentIntentCommand
{
    public function __construct(
        private PaymentIntentId $paymentIntentId,
        private string $reason,
    ) {}

    public function paymentIntentId(): PaymentIntentId
    {
        return $this->paymentIntentId;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
