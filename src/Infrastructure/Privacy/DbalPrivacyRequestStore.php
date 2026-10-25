<?php

declare(strict_types=1);

namespace App\Infrastructure\Privacy;

use App\Application\Privacy\PrivacyProgressAuthority;
use App\Application\Privacy\PrivacyRequestRecord;
use App\Application\Privacy\PrivacyRequestStore;
use Doctrine\DBAL\Connection;

final readonly class DbalPrivacyRequestStore implements PrivacyRequestStore
{
    public function __construct(private Connection $database)
    {
    }

    public function lock(string $accountId, bool $deletion = false): int
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Privacy intake requires an owning transaction.');
        }
        $races = $deletion ? $this->affectedRaces($accountId) : [];
        foreach ($races as $raceId) {
            $this->database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [$raceId]);
        }
        $version = $this->database->fetchOne("SELECT version FROM accounts WHERE id=? AND lifecycle_state='active' FOR UPDATE", [$accountId]);
        if (false === $version) {
            throw new \DomainException('Privacy request is unavailable.');
        }
        if (!is_int($version)) {
            throw new \UnexpectedValueException('Invalid stored privacy account.');
        }

        if ($deletion && $races !== $this->affectedRaces($accountId)) {
            throw new \DomainException('Privacy race scope changed; retry the request.');
        }

        return $version;
    }

    /** @return list<string> */
    private function affectedRaces(string $accountId): array
    {
        $rows = $this->database->fetchFirstColumn("SELECT race_id::text FROM bib_entitlements WHERE current_owner_account_id=? UNION SELECT race_id::text FROM resale_listings WHERE seller_id=? AND state IN ('draft','published') UNION SELECT i.race_id::text FROM checkout_items i WHERE i.checkout_id IN (SELECT c.id FROM checkouts c JOIN checkout_items owned ON owned.checkout_id=c.id JOIN resale_listings l ON l.id=owned.listing_id WHERE owned.protection_state='protected' AND (c.buyer_id=? OR l.seller_id=?)) ORDER BY 1", [$accountId, $accountId, $accountId, $accountId]);
        $races = [];
        foreach ($rows as $race) {
            if (!is_string($race)) {
                throw new \UnexpectedValueException('Invalid stored privacy race.');
            }
            $races[] = $race;
        }

        return $races;
    }

    public function unsoldListings(string $accountId): array
    {
        $listings = [];
        foreach ($this->database->fetchAllAssociative("SELECT id::text,version FROM resale_listings WHERE seller_id=? AND state IN ('draft','published') ORDER BY race_id,id", [$accountId]) as $row) {
            if (!is_string($row['id']) || !is_int($row['version'])) {
                throw new \UnexpectedValueException('Invalid stored privacy listing.');
            }
            $listings[] = ['id' => $row['id'], 'version' => $row['version']];
        }

        return $listings;
    }

    public function fence(string $accountId, \DateTimeImmutable $now): array
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Privacy fencing requires an owning transaction.');
        }
        $financial = $this->database->fetchOne("SELECT EXISTS(SELECT 1 FROM checkout_items i JOIN checkouts c ON c.id=i.checkout_id JOIN resale_listings l ON l.id=i.listing_id WHERE i.protection_state='protected' AND (c.buyer_id=? OR l.seller_id=?))", [$accountId, $accountId]);
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='suspended',version=version+1 WHERE id=?", [$accountId]);
        $this->database->executeStatement('UPDATE account_credentials SET consumed_at=? WHERE account_id=? AND consumed_at IS NULL', [$now->format(DATE_ATOM), $accountId]);

        return true === $financial ? ['retention_policy', 'financial_resolution'] : ['retention_policy'];
    }

    public function existing(string $requestId): ?PrivacyRequestRecord
    {
        $owner = $this->database->fetchOne("SELECT actor_account_id::text FROM administrative_audit WHERE id=? AND action='privacy.requested'", [$requestId]);
        if (false === $owner) {
            return null;
        }
        if (!is_string($owner)) {
            throw new \UnexpectedValueException('Invalid stored privacy owner.');
        }
        $row = $this->database->fetchOne("SELECT authority_snapshot::text FROM administrative_audit WHERE subject_kind='privacy_request' AND subject_id=? ORDER BY (authority_snapshot->>'request_version')::bigint DESC,id DESC LIMIT 1", [$requestId]);
        if (!is_string($row)) {
            throw new \UnexpectedValueException('Invalid stored privacy progress.');
        }
        $data = json_decode($row, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['kind'] ?? null) || !is_string($data['state'] ?? null) || !is_int($data['account_version'] ?? null) || !is_string($data['authority_expires_at'] ?? null) || !is_array($data['blockers'] ?? null) || !array_is_list($data['blockers'])) {
            throw new \UnexpectedValueException('Invalid stored privacy progress.');
        }
        $blockers = [];
        foreach ($data['blockers'] as $blocker) {
            if (!is_string($blocker)) {
                throw new \UnexpectedValueException('Invalid stored privacy blocker.');
            }
            $blockers[] = $blocker;
        }

        return new PrivacyRequestRecord($requestId, $owner, $data['kind'], $data['state'], $data['account_version'], new \DateTimeImmutable($data['authority_expires_at']), $blockers);
    }

    public function current(PrivacyProgressAuthority $authority): ?PrivacyRequestRecord
    {
        $record = $this->existing($authority->requestId);
        if (null === $record || $record->accountId !== $authority->accountId || $record->accountVersion !== $authority->accountVersion || $record->authorityExpiresAt != $authority->expiresAt) {
            return null;
        }
        $version = $this->database->fetchOne('SELECT version FROM accounts WHERE id=?', [$authority->accountId]);
        if (!is_int($version)) {
            throw new \UnexpectedValueException('Invalid stored privacy account.');
        }

        return $version === $authority->accountVersion ? $record : null;
    }
}
