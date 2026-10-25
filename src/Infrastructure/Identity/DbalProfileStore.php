<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\AccountProfile;
use App\Application\Identity\ProfileStore;
use App\Application\Identity\PublicProfile;
use Doctrine\DBAL\Connection;

final readonly class DbalProfileStore implements ProfileStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function privateProfile(string $accountId): ?AccountProfile
    {
        $row = $this->connection->fetchAssociative("SELECT p.email,p.display_name,a.optional_email_enabled,a.optional_in_app_enabled,a.version FROM accounts a JOIN account_private_profiles p ON p.account_id=a.id WHERE a.id=? AND a.lifecycle_state='active'", [$accountId]);
        if (false === $row) {
            return null;
        }
        if (!is_string($row['email']) || (!is_string($row['display_name']) && null !== $row['display_name']) || !is_bool($row['optional_email_enabled']) || !is_bool($row['optional_in_app_enabled']) || (!is_int($row['version']) && !is_string($row['version']))) {
            throw new \UnexpectedValueException('Invalid stored private profile.');
        }

        return new AccountProfile($row['email'], $row['display_name'], $row['optional_email_enabled'], $row['optional_in_app_enabled'], (int) $row['version']);
    }

    public function publicProfile(string $accountId): ?PublicProfile
    {
        $row = $this->connection->fetchAssociative("SELECT p.display_name FROM accounts a JOIN account_private_profiles p ON p.account_id=a.id WHERE a.id=? AND a.lifecycle_state='active'", [$accountId]);
        if (false === $row) {
            return null;
        }
        $bibs = [];
        foreach ($this->connection->fetchAllAssociative("SELECT r.id AS race_id,r.name AS race_name,e.bib_number FROM bib_entitlements e JOIN races r ON r.id=e.race_id WHERE e.current_owner_account_id=? AND r.publication_state='published' ORDER BY r.starts_at DESC,r.id,e.id LIMIT 50", [$accountId]) as $bib) {
            if (!is_string($bib['race_id']) || !is_string($bib['race_name']) || !is_string($bib['bib_number'])) {
                throw new \UnexpectedValueException('Invalid stored public bib.');
            }
            $bibs[] = ['race_id' => $bib['race_id'], 'race_name' => $bib['race_name'], 'bib_number' => $bib['bib_number']];
        }

        return new PublicProfile(is_string($row['display_name']) ? $row['display_name'] : null, $bibs);
    }

    public function update(string $accountId, ?string $displayName, bool $optionalEmailEnabled, bool $optionalInAppEnabled, int $expectedVersion): bool
    {
        return $this->connection->transactional(function () use ($accountId, $displayName, $optionalEmailEnabled, $optionalInAppEnabled, $expectedVersion): bool {
            $changed = $this->connection->executeStatement("UPDATE accounts SET optional_email_enabled=?,optional_in_app_enabled=?,version=version+1 WHERE id=? AND version=? AND lifecycle_state='active' AND EXISTS(SELECT 1 FROM account_private_profiles p WHERE p.account_id=accounts.id)", [$optionalEmailEnabled, $optionalInAppEnabled, $accountId, $expectedVersion], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER]);
            if (1 !== $changed) {
                return false;
            }
            $this->connection->update('account_private_profiles', ['display_name' => $displayName], ['account_id' => $accountId]);

            return true;
        });
    }
}
