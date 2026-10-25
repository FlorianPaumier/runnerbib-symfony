<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use Doctrine\DBAL\Connection;

final readonly class DbalAuditWriter implements AuditWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(AuditEntry $entry): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('An owning business transaction is required');
        }
        $this->connection->insert('administrative_audit', [
            'id' => $entry->id, 'actor_account_id' => $entry->actorAccountId, 'service_principal' => $entry->servicePrincipal,
            'authority_snapshot' => $entry->authoritySnapshot, 'action' => $entry->action,
            'subject_kind' => $entry->subjectKind, 'subject_id' => $entry->subjectId,
            'reason' => $entry->reason, 'occurred_at' => $entry->occurredAt->format('Y-m-d H:i:s.uP'), 'outcome' => $entry->outcome,
        ]);
    }
}
