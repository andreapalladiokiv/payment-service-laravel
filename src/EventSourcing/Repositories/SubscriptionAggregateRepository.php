<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\EventSourcing\Repositories;

use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\ClassNameInflector;
use EventSauce\EventSourcing\EventSourcedAggregateRootRepository;
use EventSauce\EventSourcing\MessageDecorator;
use EventSauce\EventSourcing\MessageDispatcher;
use EventSauce\EventSourcing\MessageRepository;
use EventSauce\EventSourcing\Snapshotting\AggregateRootRepositoryWithSnapshotting;
use EventSauce\EventSourcing\Snapshotting\AggregateRootWithSnapshotting;
use EventSauce\EventSourcing\Snapshotting\ConstructingAggregateRootRepositoryWithSnapshotting;
use EventSauce\EventSourcing\Snapshotting\SnapshotRepository;
use Override;
use Techork\PaymentService\Domain\Subscription\SubscriptionAggregate;
use Techork\PaymentService\Domain\Subscription\SubscriptionAggregateRepositoryInterface;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;

/**
 * @extends EventSourcedAggregateRootRepository<SubscriptionAggregate>
 */
final class SubscriptionAggregateRepository extends EventSourcedAggregateRootRepository implements AggregateRootRepositoryWithSnapshotting, SubscriptionAggregateRepositoryInterface
{
    private readonly ConstructingAggregateRootRepositoryWithSnapshotting $snapshottingRepository;

    public function __construct(
        MessageRepository $messageRepository,
        private readonly SnapshotRepository $snapshotRepository,
        ?MessageDispatcher $dispatcher = null,
        ?MessageDecorator $decorator = null,
        ?ClassNameInflector $classNameInflector = null,
    ) {
        parent::__construct(SubscriptionAggregate::class, $messageRepository, $dispatcher, $decorator, $classNameInflector);

        $innerRepository = new EventSourcedAggregateRootRepository(
            SubscriptionAggregate::class,
            $messageRepository,
            $dispatcher,
            $decorator,
            $classNameInflector,
        );

        $this->snapshottingRepository = new ConstructingAggregateRootRepositoryWithSnapshotting(
            SubscriptionAggregate::class,
            $messageRepository,
            $this->snapshotRepository,
            $innerRepository,
        );
    }

    #[Override]
    public function retrieve(AggregateRootId|SubscriptionId $aggregateRootId): SubscriptionAggregate
    {
        return $this->snapshottingRepository->retrieveFromSnapshot($aggregateRootId);
    }

    #[Override]
    public function retrieveFromSnapshot(AggregateRootId $aggregateRootId): object
    {
        return $this->snapshottingRepository->retrieveFromSnapshot($aggregateRootId);
    }

    #[Override]
    public function storeSnapshot(AggregateRootWithSnapshotting $aggregateRoot): void
    {
        $this->snapshottingRepository->storeSnapshot($aggregateRoot);
    }
}
