<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\EventSourcing\Decorators;

use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\MessageDecorator;
use Illuminate\Log\Context\Repository as ContextRepository;
use Override;

final readonly class GatewayIdMessageDecorator implements MessageDecorator
{
    public const string GATEWAY_ID = '__gateway_id';

    public function __construct(private ContextRepository $context) {}

    #[Override]
    public function decorate(Message $message): Message
    {
        return $message->withHeaders([
            self::GATEWAY_ID => (string)$this->context->get('gateway_id'),
        ]);
    }
}
