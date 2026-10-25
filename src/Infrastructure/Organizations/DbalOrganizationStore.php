<?php

declare(strict_types=1);

namespace App\Infrastructure\Organizations;

use App\Application\Organizations\OrganizationStore;
use App\Domain\Organizations\Organization;
use Doctrine\DBAL\Connection;

final readonly class DbalOrganizationStore implements OrganizationStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function transaction(\Closure $operation): void
    {
        try {
            $this->connection->transactional($operation);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $exception) {
            throw new \DomainException('Organization change conflicts with stored state.', 0, $exception);
        }
    }

    public function actor(string $accountId): ?\App\Domain\Identity\Actor
    {
        $roles = $this->connection->fetchOne("SELECT roles FROM accounts WHERE id=:id AND lifecycle_state='active' AND verified_at IS NOT NULL", ['id' => $accountId]);
        if (false === $roles) {
            return null;
        }
        if (!is_string($roles)) {
            throw new \UnexpectedValueException('Invalid stored account roles.');
        }
        $values = json_decode($roles, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($values) && array_is_list($values));
        $typed = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \UnexpectedValueException('Invalid stored account role.');
            }
            $typed[] = $value;
        }

        return new \App\Domain\Identity\Actor($accountId, $typed);
    }

    public function membershipRole(string $organizationId, string $accountId): ?string
    {
        $role = $this->connection->fetchOne('SELECT role FROM organization_memberships WHERE organization_id=:organization AND account_id=:account AND ended_at IS NULL', ['organization' => $organizationId, 'account' => $accountId]);
        assert(false === $role || is_string($role));

        return false === $role ? null : $role;
    }

    public function lockOrganization(string $id): void
    {
        $this->connection->executeQuery('SELECT id FROM organizations WHERE id=:id FOR UPDATE', ['id' => $id]);
    }

    public function endMembership(string $organizationId, string $accountId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE organization_memberships SET ended_at=:now WHERE organization_id=:organization AND account_id=:account AND ended_at IS NULL', ['now' => $now->format(DATE_ATOM), 'organization' => $organizationId, 'account' => $accountId]);
    }

    public function history(string $organizationId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT account_id,role,ended_at FROM organization_memberships WHERE organization_id=:id ORDER BY joined_at,id', ['id' => $organizationId]);

        return array_map(static function (array $row): \App\Domain\Organizations\Membership {
            assert(is_string($row['account_id']) && is_string($row['role']) && (null === $row['ended_at'] || is_string($row['ended_at'])));

            return new \App\Domain\Organizations\Membership($row['account_id'], $row['role'], $row['ended_at']);
        }, $rows);
    }

    public function organizationIdForRace(string $raceId): ?string
    {
        $id = $this->connection->fetchOne('SELECT organization_id FROM races WHERE id=:id', ['id' => $raceId]);
        assert(false === $id || null === $id || is_string($id));

        return false === $id ? null : $id;
    }

    public function activeOrganizationId(string $accountId): ?string
    {
        $id = $this->connection->fetchOne('SELECT organization_id FROM organization_memberships WHERE account_id=:id AND ended_at IS NULL', ['id' => $accountId]);
        assert(false === $id || is_string($id));

        return false === $id ? null : $id;
    }

    public function newAuditId(): string
    {
        $id = $this->connection->fetchOne('SELECT gen_random_uuid()');
        assert(is_string($id));

        return $id;
    }

    public function setApprovalState(string $id, string $state): void
    {
        $this->connection->executeStatement('UPDATE organizations SET approval_state=:state,version=version+1 WHERE id=:id', ['state' => $state, 'id' => $id]);
    }

    public function raceIdForImport(string $importId): ?string
    {
        $id = $this->connection->fetchOne('SELECT race_id FROM eligibility_imports WHERE id=:id', ['id' => $importId]);
        assert(false === $id || is_string($id));

        return false === $id ? null : $id;
    }

    public function create(string $id, string $name): void
    {
        $this->connection->insert('organizations', ['id' => $id, 'name' => $name]);
    }

    public function addMembership(string $id, string $organizationId, string $accountId, string $role, \DateTimeImmutable $now): void
    {
        $this->connection->insert('organization_memberships', ['id' => $id, 'organization_id' => $organizationId, 'account_id' => $accountId, 'role' => $role, 'joined_at' => $now->format(DATE_ATOM)]);
    }

    public function get(string $id): Organization
    {
        $row = $this->connection->fetchAssociative("SELECT o.id,o.name,o.approval_state,o.version,m.account_id FROM organizations o JOIN organization_memberships m ON m.organization_id=o.id AND m.role='owner' AND m.ended_at IS NULL WHERE o.id=:id", ['id' => $id]);
        if (false === $row) {
            throw new \DomainException('Organization has no active owner or does not exist.');
        }
        assert(is_string($row['id']) && is_string($row['name']) && is_string($row['approval_state']) && is_string($row['account_id']) && (is_int($row['version']) || is_string($row['version'])));

        return new Organization($row['id'], $row['name'], $row['approval_state'], $row['account_id'], (int) $row['version']);
    }

    public function approve(string $id, string $actorAccountId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("UPDATE organizations SET approval_state='approved',approved_by=:actor,approved_at=:now,version=version+1 WHERE id=:id", ['actor' => $actorAccountId, 'now' => $now->format(DATE_ATOM), 'id' => $id]);
    }
}
