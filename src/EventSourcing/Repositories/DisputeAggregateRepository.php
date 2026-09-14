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
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeAggregateRepositoryInterface;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;

/**
 * The dispute aggregate's stream, in the same two tables every other aggregate here uses.
 *
 * ## Why this is a class of its own rather than a shared generic repository
 *
 * Every repository in this directory is the same eleven lines around a different aggregate class,
 * and the duplication is the point: the aggregate class is a *compile-time* argument, so a generic
 * one would have to be constructed with a class string and would lose the return type on
 * `retrieve()`. That return type is what makes a recorder fail at the first call rather than the
 * first use — a wrong class wired here is a psalm error, not a runtime surprise on a live dispute.
 *
 * ## Nothing new is stored, and nothing is migrated
 *
 * It shares `stored_events` and `aggregate_snapshots` with the other three, so a dispute stream is
 * a stream like any other: same tables, same serialiser, same decorators, the aggregate class on
 * the event's headers being the only thing that tells them apart. That is deliberate against the
 * plan's rule 7 — no model, no migration, no job, no scheduled command for this context — and it
 * is what lets the recorder below be wired with exactly what the other repositories are wired with.
 *
 * `#[Override]` on `retrieve()` because the parent already declares it against the EventSauce
 * aggregate id; the narrower parameter is the same narrowing {@see PaymentIntentAggregateRepository}
 * makes and is checked by psalm, not by PHP.
 *
 * @extends EventSourcedAggregateRootRepository<DisputeAggregate>
 * @implements AggregateRootRepositoryWithSnapshotting<DisputeAggregate>
 */
final class DisputeAggregateRepository extends EventSourcedAggregateRootRepository implements AggregateRootRepositoryWithSnapshotting, DisputeAggregateRepositoryInterface
{
    private readonly ConstructingAggregateRootRepositoryWithSnapshotting $snapshottingRepository;

    public function __construct(
        MessageRepository $messageRepository,
        private readonly SnapshotRepository $snapshotRepository,
        ?MessageDispatcher $dispatcher = null,
        ?MessageDecorator $decorator = null,
        ?ClassNameInflector $classNameInflector = null,
    ) {
        parent::__construct(DisputeAggregate::class, $messageRepository, $dispatcher, $decorator, $classNameInflector);

        $innerRepository = new EventSourcedAggregateRootRepository(
            DisputeAggregate::class,
            $messageRepository,
            $dispatcher,
            $decorator,
            $classNameInflector,
        );

        $this->snapshottingRepository = new ConstructingAggregateRootRepositoryWithSnapshotting(
            DisputeAggregate::class,
            $messageRepository,
            $this->snapshotRepository,
            $innerRepository,
        );
    }

    #[Override]
    public function retrieve(AggregateRootId|DisputeId $aggregateRootId): DisputeAggregate
    {
        $aggregate = $this->snapshottingRepository->retrieveFromSnapshot($aggregateRootId);

        // EventSauce's snapshotting repository is typed to the interface, but this one was
        // constructed for a single aggregate class, so it can only hand that back.
        assert($aggregate instanceof DisputeAggregate);

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
