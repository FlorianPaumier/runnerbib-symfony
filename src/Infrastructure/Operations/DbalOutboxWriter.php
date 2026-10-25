<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\OutboxMessage;
use App\Application\Operations\OutboxWriter;
use Doctrine\DBAL\Connection;

final readonly class DbalOutboxWriter implements OutboxWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(OutboxMessage $message): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('An owning business transaction is required');
        }
        $this->connection->insert('outbox_messages', [
            'id' => $message->id, 'effect_key' => $message->effectKey,
            'aggregate_kind' => $message->aggregateKind, 'aggregate_id' => $message->aggregateId,
            'aggregate_version' => $message->aggregateVersion, 'event_type' => $message->eventType,
            'payload_version' => $message->payloadVersion, 'payload' => $message->payload,
            'created_at' => $message->createdAt->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
