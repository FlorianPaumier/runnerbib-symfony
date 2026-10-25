<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\ClaimedOutboxMessage;
use App\Application\Operations\OutboxMessage;
use App\Application\Operations\OutboxQueue;
use Doctrine\DBAL\Connection;

final readonly class DbalOutboxQueue implements OutboxQueue
{
    public function __construct(private Connection $connection)
    {
    }

    public function claim(\DateTimeImmutable $now, string $worker): ?ClaimedOutboxMessage
    {
        return $this->connection->transactional(function () use ($now, $worker): ?ClaimedOutboxMessage {
            $instant = $now->format('Y-m-d H:i:s.uP');
            $row = $this->connection->fetchAssociative('SELECT id,effect_key,aggregate_kind,aggregate_id,aggregate_version::text,event_type,payload_version::text,payload,created_at,fencing_generation::text FROM outbox_messages WHERE dispatched_at IS NULL AND (delivery_state->\'dispatch\'->>\'state\' IS DISTINCT FROM \'failed\') AND (next_attempt_at IS NULL OR next_attempt_at<=?) AND (lease_until IS NULL OR lease_until<=?) ORDER BY next_attempt_at,created_at,id FOR UPDATE SKIP LOCKED LIMIT 1', [$instant, $instant]);
            if (false === $row) {
                return null;
            }
            $generation = (int) $this->text($row['fencing_generation']) + 1;
            $this->connection->executeStatement('UPDATE outbox_messages SET claimed_by=?,lease_until=?,fencing_generation=? WHERE id=?', [$worker, $now->modify('+60 seconds')->format('Y-m-d H:i:s.uP'), $generation, $this->text($row['id'])]);
            $message = new OutboxMessage($this->text($row['id']), $this->text($row['effect_key']), $this->text($row['aggregate_kind']), $this->text($row['aggregate_id']), (int) $this->text($row['aggregate_version']), $this->text($row['event_type']), (int) $this->text($row['payload_version']), $this->text($row['payload']), new \DateTimeImmutable($this->text($row['created_at'])));

            return new ClaimedOutboxMessage($message, $worker, $generation);
        });
    }

    private function text(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Invalid stored outbox field');
        }

        return $value;
    }

    public function acknowledge(ClaimedOutboxMessage $claim, \DateTimeImmutable $now): bool
    {
        return 1 === $this->connection->executeStatement('UPDATE outbox_messages SET dispatched_at=?,claimed_by=NULL,lease_until=NULL WHERE id=? AND claimed_by=? AND fencing_generation=? AND dispatched_at IS NULL', [$now->format('Y-m-d H:i:s.uP'), $claim->message->id, $claim->worker, $claim->generation]);
    }

    public function retry(ClaimedOutboxMessage $claim, \DateTimeImmutable $nextAttempt, int $maximumAttempts): bool
    {
        $id = $this->text($claim->message->id);
        $deferred = $nextAttempt->format('Y-m-d H:i:s.uP');

        return 1 === $this->connection->executeStatement(
            "UPDATE outbox_messages m SET next_attempt_at=CASE WHEN a.attempts>=? THEN NULL ELSE ?::timestamptz END,claimed_by=NULL,lease_until=NULL,delivery_state=jsonb_set(m.delivery_state,'{dispatch}',jsonb_build_object('state',CASE WHEN a.attempts>=? THEN 'failed' ELSE 'pending' END,'attempts',a.attempts,'deferred_until',?::text)) FROM (SELECT CASE WHEN delivery_state->'dispatch'->>'attempts' ~ '^[0-9]+$' THEN (delivery_state->'dispatch'->>'attempts')::int ELSE 0 END+1 AS attempts FROM outbox_messages WHERE id=?) a WHERE m.id=? AND m.claimed_by=? AND m.fencing_generation=? AND m.dispatched_at IS NULL",
            [$maximumAttempts, $deferred, $maximumAttempts, $deferred, $id, $id, $claim->worker, $claim->generation]
        );
    }
}
