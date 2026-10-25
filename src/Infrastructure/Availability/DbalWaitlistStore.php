<?php

declare(strict_types=1);

namespace App\Infrastructure\Availability;

use App\Application\Availability\WaitlistStore;
use App\Domain\Availability\WaitlistMembership;
use Doctrine\DBAL\Connection;

final readonly class DbalWaitlistStore implements WaitlistStore
{
    public function __construct(private Connection $database)
    {
    }

    public function requireEligibleAccount(string $accountId): void
    {
        if (false === $this->database->fetchOne("SELECT id FROM accounts WHERE id = ? AND lifecycle_state = 'active' AND verified_at IS NOT NULL", [$accountId])) {
            throw new \DomainException('An active verified account is required.');
        }
    }

    public function coordinateRace(string $raceId): void
    {
        $this->database->fetchOne('SELECT id FROM races WHERE id = ? FOR UPDATE', [$raceId]);
    }

    public function lockPublishedRace(string $raceId): void
    {
        if (false === $this->database->fetchOne("SELECT id FROM races WHERE id = ? AND publication_state = 'published' FOR UPDATE", [$raceId])) {
            throw new \DomainException('Race is unavailable.');
        }
    }

    public function active(string $accountId, string $raceId): ?WaitlistMembership
    {
        $row = $this->database->fetchAssociative('SELECT id::text, race_id::text, account_id::text, email_enabled, in_app_enabled FROM race_waitlist_memberships WHERE account_id = ? AND race_id = ? AND ended_at IS NULL', [$accountId, $raceId]);
        if (false === $row) {
            return null;
        }

        return $this->membership($row);
    }

    public function owned(string $accountId, string $membershipId): ?WaitlistMembership
    {
        $row = $this->database->fetchAssociative('SELECT id::text, race_id::text, account_id::text, email_enabled, in_app_enabled FROM race_waitlist_memberships WHERE account_id = ? AND id = ?', [$accountId, $membershipId]);
        if (false === $row) {
            return null;
        }

        return $this->membership($row);
    }

    public function changePreferences(string $membershipId, bool $emailEnabled, bool $inAppEnabled): void
    {
        if (1 !== $this->database->executeStatement('UPDATE race_waitlist_memberships SET email_enabled = ?, in_app_enabled = ? WHERE id = ? AND ended_at IS NULL', [$emailEnabled, $inAppEnabled, $membershipId], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING])) {
            throw new \DomainException('Membership is unavailable.');
        }
    }

    public function end(string $membershipId, \DateTimeImmutable $now): bool
    {
        return 1 === $this->database->executeStatement('UPDATE race_waitlist_memberships SET ended_at = ? WHERE id = ? AND ended_at IS NULL', [$now->format(DATE_ATOM), $membershipId]);
    }

    /** @param array<string, mixed> $row */
    private function membership(array $row): WaitlistMembership
    {
        if (!is_string($row['id']) || !is_string($row['race_id']) || !is_string($row['account_id']) || !is_bool($row['email_enabled']) || !is_bool($row['in_app_enabled'])) {
            throw new \UnexpectedValueException('Invalid stored membership.');
        }

        return new WaitlistMembership($row['id'], $row['race_id'], $row['account_id'], $row['email_enabled'], $row['in_app_enabled']);
    }

    public function add(WaitlistMembership $membership, \DateTimeImmutable $now): void
    {
        $this->database->executeStatement('INSERT INTO race_waitlist_memberships(id,race_id,account_id,joined_at,email_enabled,in_app_enabled) VALUES (?,?,?,?,?,?)', [$membership->id, $membership->raceId, $membership->accountId, $now->format(DATE_ATOM), $membership->emailEnabled, $membership->inAppEnabled], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN]);
    }
}
