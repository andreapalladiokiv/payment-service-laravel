<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Command;

use Money\Money;
use Override;
use Techork\PaymentService\Domain\PaymentIntent\Command\CapturePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

final readonly class CapturePaymentIntent implements CapturePaymentIntentCommand
{
    public function __construct(
        private PaymentIntentId $paymentIntentId,
        private Money $amount,
    ) {}

    #[Override]
    public function paymentIntentId(): PaymentIntentId
    {
        return $this->paymentIntentId;
    }

    #[Override]
    public function amount(): Money
    {
        return $this->amount;
    }
}
