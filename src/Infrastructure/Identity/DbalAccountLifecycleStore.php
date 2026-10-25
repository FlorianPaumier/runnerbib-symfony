<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\AccountLifecycleStore;
use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Domain\Identity\AccountLifecycle;
use App\Domain\Identity\Actor;
use Doctrine\DBAL\Connection;

final readonly class DbalAccountLifecycleStore implements AccountLifecycleStore
{
    public function __construct(private Connection $connection, private AuditWriter $audit)
    {
    }

    public function change(string $actorId, string $targetId, AccountLifecycle $change, string $auditId, \DateTimeImmutable $now): bool
    {
        return $this->connection->transactional(function () use ($actorId, $targetId, $change, $auditId, $now): bool {
            $accounts = [];
            foreach ($this->connection->fetchAllAssociative('SELECT id,lifecycle_state,roles FROM accounts WHERE id IN (?,?) ORDER BY id FOR UPDATE', [$actorId, $targetId]) as $row) {
                if (!is_string($row['id']) || !is_string($row['lifecycle_state']) || !is_string($row['roles'])) {
                    throw new \UnexpectedValueException('Invalid stored account authority.');
                }
                $accounts[$row['id']] = ['state' => $row['lifecycle_state'], 'roles' => $row['roles']];
            }
            $actorData = $accounts[$actorId] ?? null;
            $targetData = $accounts[$targetId] ?? null;
            if (null === $actorData || null === $targetData || 'active' !== $actorData['state']) {
                return false;
            }
            $decodedRoles = json_decode($actorData['roles'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decodedRoles)) {
                return false;
            }
            $roles = [];
            foreach ($decodedRoles as $role) {
                if (!is_string($role)) {
                    return false;
                } $roles[] = $role;
            }
            $actor = new Actor($actorId, $roles);
            $nextState = $change->transitionFrom($targetData['state']);
            if (!$actor->canAdministerAccounts() || null === $nextState) {
                return false;
            }
            $this->connection->executeStatement('UPDATE accounts SET lifecycle_state=?,version=version+1 WHERE id=?', [$nextState, $targetId]);
            $this->connection->executeStatement('UPDATE account_credentials SET consumed_at=? WHERE account_id=? AND consumed_at IS NULL', [$now->format(DATE_ATOM), $targetId]);
            $this->audit->append(new AuditEntry($auditId, $actorId, null, json_encode(['roles' => $roles, 'previous_state' => $targetData['state'], 'state' => $nextState], JSON_THROW_ON_ERROR), 'suspended' === $nextState ? 'account.suspended' : 'account.reactivated', 'account', $targetId, $change->reason, $now, 'completed'));

            return true;
        });
    }
}
