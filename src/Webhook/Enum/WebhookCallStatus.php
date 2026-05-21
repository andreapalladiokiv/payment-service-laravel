<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Enum;

enum WebhookCallStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
