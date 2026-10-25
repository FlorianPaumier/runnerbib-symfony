<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\RetentionStore;
use Doctrine\DBAL\Connection;

final readonly class DbalRetentionStore implements RetentionStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function purge(array $cutoffs): array
    {
        $at = [];
        foreach ($cutoffs as $table => $cutoff) {
            $at[$table] = $cutoff->format('Y-m-d H:i:s.uP');
        }
        $counts = [];
        $counts['outbox_messages'] = (int) $this->connection->executeStatement('DELETE FROM outbox_messages WHERE dispatched_at IS NOT NULL AND dispatched_at<?', [$at['outbox_messages']]);
        $counts['account_credentials'] = (int) $this->connection->executeStatement('DELETE FROM account_credentials WHERE (consumed_at IS NOT NULL AND consumed_at<?) OR (consumed_at IS NULL AND expires_at<?)', [$at['account_credentials'], $at['account_credentials']]);
        $counts['consumed_effects'] = (int) $this->connection->executeStatement('DELETE FROM consumed_effects WHERE completed_at<?', [$at['consumed_effects']]);
        $counts['notifications'] = (int) $this->connection->executeStatement("DELETE FROM notifications WHERE deadline<? AND delivery_state->>'email'<>'pending'", [$at['notifications']]);
        $counts['scheduled_runs'] = (int) $this->connection->executeStatement("DELETE FROM scheduled_runs s WHERE s.state='completed' AND s.completed_at<? AND NOT EXISTS(SELECT 1 FROM financial_operation_attempts a WHERE a.scheduled_run_id=s.id)", [$at['scheduled_runs']]);
        $counts['provider_event_inbox'] = (int) $this->connection->executeStatement('DELETE FROM provider_event_inbox e WHERE e.received_at<? AND NOT EXISTS(SELECT 1 FROM payment_attempts p WHERE p.validation_event_id=e.id)', [$at['provider_event_inbox']]);

        return $counts;
    }
}
