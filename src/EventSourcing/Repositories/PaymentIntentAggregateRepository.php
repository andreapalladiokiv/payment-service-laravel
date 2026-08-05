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
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregateRepositoryInterface;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * @extends EventSourcedAggregateRootRepository<PaymentIntentAggregate>
 * @implements AggregateRootRepositoryWithSnapshotting<PaymentIntentAggregate>
 */
final class PaymentIntentAggregateRepository extends EventSourcedAggregateRootRepository implements AggregateRootRepositoryWithSnapshotting, PaymentIntentAggregateRepositoryInterface
{
    private readonly ConstructingAggregateRootRepositoryWithSnapshotting $snapshottingRepository;

    public function __construct(
        MessageRepository $messageRepository,
        private readonly SnapshotRepository $snapshotRepository,
        ?MessageDispatcher $dispatcher = null,
        ?MessageDecorator $decorator = null,
        ?ClassNameInflector $classNameInflector = null,
    ) {
        parent::__construct(PaymentIntentAggregate::class, $messageRepository, $dispatcher, $decorator, $classNameInflector);

        $innerRepository = new EventSourcedAggregateRootRepository(
            PaymentIntentAggregate::class,
            $messageRepository,
            $dispatcher,
            $decorator,
            $classNameInflector,
        );

        $this->snapshottingRepository = new ConstructingAggregateRootRepositoryWithSnapshotting(
            PaymentIntentAggregate::class,
            $messageRepository,
            $this->snapshotRepository,
            $innerRepository,
        );
    }

    #[Override]
    public function retrieve(AggregateRootId|PaymentIntentId $aggregateRootId): PaymentIntentAggregate
    {
        $aggregate = $this->snapshottingRepository->retrieveFromSnapshot($aggregateRootId);

        // EventSauce's snapshotting repository is typed to the interface, but this one was
        // constructed for a single aggregate class, so it can only hand that back.
        assert($aggregate instanceof PaymentIntentAggregate);

        return $aggregate;
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
