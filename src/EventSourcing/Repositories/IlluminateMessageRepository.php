<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\EventSourcing\Repositories;

use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\Header;
use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\MessageRepository;
use EventSauce\EventSourcing\OffsetCursor;
use EventSauce\EventSourcing\PaginationCursor;
use EventSauce\EventSourcing\Serialization\MessageSerializer;
use EventSauce\EventSourcing\UnableToPersistMessages;
use EventSauce\EventSourcing\UnableToRetrieveMessages;
use Generator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Override;
use Ramsey\Uuid\Uuid;
use Throwable;
use function count;
use function json_decode;
use function json_encode;

/**
 * Inlined from eventsauce/message-repository-for-illuminate to remove
 * the Laravel version constraint that blocks upgrading to Laravel 13.
 *
 * Uses string ID encoding (no binary UUIDs) and the default table schema
 * (id, event_id, aggregate_root_id, version, payload).
 */
final readonly class IlluminateMessageRepository implements MessageRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private string              $tableName,
        private MessageSerializer   $serializer,
        private int                 $jsonEncodeOptions = 0,
    ) {}

    #[Override]
    public function persist(Message ...$messages): void
    {
        if (count($messages) === 0) {
            return;
        }

        $values = [];

        foreach ($messages as $message) {
            $payload = $this->serializer->serializeMessage($message);
            $payload['headers'][Header::EVENT_ID] ??= Uuid::uuid7()->toString();

            $values[] = [
                'version' => $payload['headers'][Header::AGGREGATE_ROOT_VERSION] ?? 0,
                'event_id' => $payload['headers'][Header::EVENT_ID],
                'payload' => json_encode($payload, $this->jsonEncodeOptions),
                'aggregate_root_id' => $message->aggregateRootId()->toString(),
            ];
        }

        try {
            $this->connection->table($this->tableName)->insert($values);
        } catch (Throwable $exception) {
            throw UnableToPersistMessages::dueTo('', $exception);
        }
    }

    #[Override]
    public function retrieveAll(AggregateRootId $id): Generator
    {
        $builder = $this->connection->table($this->tableName)
            ->where('aggregate_root_id', $id->toString())
            ->orderBy('version', 'ASC');

        try {
            return $this->yieldMessagesForResult($builder->get(['payload']));
        } catch (Throwable $exception) {
            throw UnableToRetrieveMessages::dueTo('', $exception);
        }
    }

    /** @psalm-return Generator<Message> */
    #[Override]
    public function retrieveAllAfterVersion(AggregateRootId $id, int $aggregateRootVersion): Generator
    {
        $builder = $this->connection->table($this->tableName)
            ->where('aggregate_root_id', $id->toString())
            ->where('version', '>', $aggregateRootVersion)
            ->orderBy('version', 'ASC');

        try {
            return $this->yieldMessagesForResult($builder->get(['payload']));
        } catch (Throwable $exception) {
            throw UnableToRetrieveMessages::dueTo('', $exception);
        }
    }

    /**
     * @param Collection<int, mixed> $result
     * @psalm-return Generator<int, Message>
     */
    private function yieldMessagesForResult(Collection $result): Generator
    {
        foreach ($result as $row) {
            yield $message = $this->serializer->unserializePayload(json_decode($row->payload, true));
        }

        return isset($message) ? $message->header(Header::AGGREGATE_ROOT_VERSION) ?: 0 : 0;
    }

    #[Override]
    public function paginate(PaginationCursor $cursor): Generator
    {
        $offsetCursor = OffsetCursor::fromString($cursor->toString());
        $offset = $offsetCursor->offset();

        $builder = $this->connection->table($this->tableName)
            ->limit($offsetCursor->limit())
            ->where('id', '>', $offset)
            ->orderBy('id', 'ASC');

        try {
            $result = $builder->get(['id', 'payload']);

            foreach ($result as $row) {
                $offset = $row->id;
                yield $this->serializer->unserializePayload(json_decode($row->payload, true));
            }

            return $offsetCursor->withOffset($offset);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMessages::dueTo('', $exception);
        }
    }
}
