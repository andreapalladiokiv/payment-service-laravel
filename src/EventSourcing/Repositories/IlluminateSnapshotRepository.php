<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\EventSourcing\Repositories;

use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\Snapshotting\Snapshot;
use EventSauce\EventSourcing\Snapshotting\SnapshotRepository;
use Illuminate\Database\ConnectionInterface;
use Override;

final readonly class IlluminateSnapshotRepository implements SnapshotRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private string              $table = 'aggregate_snapshots',
    ) {}

    #[Override]
    public function persist(Snapshot $snapshot): void
    {
        $this->connection->table($this->table)->upsert(
            [
                'aggregate_root_id' => $snapshot->aggregateRootId()->toString(),
                'aggregate_root_version' => $snapshot->aggregateRootVersion(),
                'state' => json_encode($snapshot->state(), JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ],
            ['aggregate_root_id'],
            ['aggregate_root_version', 'state', 'created_at'],
        );
    }

    #[Override]
    public function retrieve(AggregateRootId $id): ?Snapshot
    {
        $row = $this->connection->table($this->table)
            ->where('aggregate_root_id', $id->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return new Snapshot(
            $id,
            max(0, (int) $row->aggregate_root_version),
            json_decode($row->state, true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
