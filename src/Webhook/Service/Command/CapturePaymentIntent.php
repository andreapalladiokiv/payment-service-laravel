<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Command;

use Money\Money;
use Techork\PaymentService\Domain\PaymentIntent\Command\CapturePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

final readonly class CapturePaymentIntent implements CapturePaymentIntentCommand
{
    public function __construct(
        private PaymentIntentId $paymentIntentId,
        private Money $amount,
    ) {}

    public function paymentIntentId(): PaymentIntentId
    {
        return $this->paymentIntentId;
    }

    public function amount(): Money
    {
        return $this->amount;
    }
}
