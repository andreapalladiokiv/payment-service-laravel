<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\EventSourcing\Consumers;

use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\MessageConsumer;
use EventSauce\EventSourcing\ReplayingMessages\TriggerBeforeReplay;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Override;

final readonly class LaravelMessageConsumer implements MessageConsumer, TriggerBeforeReplay
{
    /**
     * @param list<class-string<Model>> $readModels
     */
    public function __construct(
        private Dispatcher $dispatcher,
        #[Tag('es.replayable_models')] private iterable $readModels = [],
    ) {}

    #[Override]
    public function handle(Message $message): void
    {
        $event = $message->payload();

        $this->dispatcher->dispatch($event::class, [$event, $message]);
    }

    #[Override]
    public function beforeReplay(): void
    {
        foreach ($this->readModels as $model) {
            $model::query()->truncate();
        }
    }
}
