<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\IdentityStore;
use Doctrine\DBAL\Connection;

final readonly class DbalIdentityStore implements IdentityStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function register(string $id, string $email, string $passwordHash, string $termsVersion, \DateTimeImmutable $now): bool
    {
        return $this->connection->transactional(function () use ($id, $email, $passwordHash, $termsVersion, $now): bool {
            // Lock the normalized identity so concurrent duplicate registrations share the same decision.
            $normalized = strtolower(trim($email));
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$normalized]);
            if (false !== $this->connection->fetchOne('SELECT account_id FROM account_private_profiles WHERE email_normalized=?', [$normalized])) {
                return false;
            }
            $this->connection->insert('accounts', ['id' => $id, 'password_hash' => $passwordHash, 'terms_version' => $termsVersion, 'terms_accepted_at' => $now->format(DATE_ATOM)]);
            $this->connection->insert('account_private_profiles', ['account_id' => $id, 'email' => trim($email), 'email_normalized' => $normalized]);

            return true;
        });
    }

    public function findActive(string $email): ?array
    {
        $row = $this->connection->fetchAssociative("SELECT a.id,a.password_hash,a.roles,a.verified_at,a.version FROM accounts a JOIN account_private_profiles p ON p.account_id=a.id WHERE p.email_normalized=? AND a.lifecycle_state='active'", [strtolower(trim($email))]);
        if (false === $row) {
            return null;
        }
        $roles = json_decode(is_string($row['roles']) ? $row['roles'] : '', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($roles) || !array_is_list($roles)) {
            throw new \UnexpectedValueException('Invalid stored account roles.');
        }
        $typedRoles = [];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new \UnexpectedValueException('Invalid stored account role.');
            }
            $typedRoles[] = $role;
        }
        if (!is_string($row['id']) || (!is_string($row['password_hash']) && null !== $row['password_hash']) || (!is_string($row['verified_at']) && null !== $row['verified_at']) || (!is_int($row['version']) && !is_string($row['version']))) {
            throw new \UnexpectedValueException('Invalid stored account.');
        }

        return ['id' => $row['id'], 'password_hash' => $row['password_hash'], 'roles' => $typedRoles, 'verified_at' => $row['verified_at'], 'version' => (int) $row['version']];
    }

    public function issueCredential(string $id, string $accountId, string $purpose, string $tokenHash, \DateTimeImmutable $now, \DateTimeImmutable $expiry): bool
    {
        return $this->connection->transactional(function () use ($id, $accountId, $purpose, $tokenHash, $now, $expiry): bool {
            $account = $this->connection->fetchAssociative('SELECT lifecycle_state,verified_at FROM accounts WHERE id=? FOR UPDATE', [$accountId]);
            if (false === $account || 'active' !== $account['lifecycle_state'] || ('verification' === $purpose && null !== $account['verified_at'])) {
                return false;
            }
            $this->connection->insert('account_credentials', ['id' => $id, 'account_id' => $accountId, 'purpose' => $purpose, 'token_hash' => $tokenHash, 'created_at' => $now->format(DATE_ATOM), 'expires_at' => $expiry->format(DATE_ATOM)]);

            return true;
        });
    }

    public function consumeCredential(string $tokenHash, string $purpose, \DateTimeImmutable $now, ?string $newPasswordHash): bool
    {
        return $this->connection->transactional(function () use ($tokenHash, $purpose, $now, $newPasswordHash): bool {
            $row = $this->connection->fetchAssociative("SELECT c.id,c.account_id FROM account_credentials c JOIN accounts a ON a.id=c.account_id WHERE c.token_hash=? AND c.purpose=? AND c.consumed_at IS NULL AND c.expires_at>? AND a.lifecycle_state='active' AND (c.purpose<>'verification' OR a.verified_at IS NULL) FOR UPDATE OF a,c", [$tokenHash, $purpose, $now->format(DATE_ATOM)]);
            if (false === $row || ('password_reset' === $purpose && null === $newPasswordHash)) {
                return false;
            }
            $this->connection->update('account_credentials', ['consumed_at' => $now->format(DATE_ATOM)], ['id' => $row['id']]);
            if ('verification' === $purpose) {
                $this->connection->executeStatement('UPDATE accounts SET verified_at=COALESCE(verified_at,?),version=version+1 WHERE id=?', [$now->format(DATE_ATOM), $row['account_id']]);
                $this->connection->executeStatement("UPDATE account_credentials SET consumed_at=? WHERE account_id=? AND purpose='verification' AND consumed_at IS NULL", [$now->format(DATE_ATOM), $row['account_id']]);
            } else {
                $this->connection->executeStatement('UPDATE accounts SET password_hash=?,version=version+1 WHERE id=?', [$newPasswordHash, $row['account_id']]);
                $this->connection->executeStatement("UPDATE account_credentials SET consumed_at=? WHERE account_id=? AND purpose='password_reset' AND consumed_at IS NULL", [$now->format(DATE_ATOM), $row['account_id']]);
            }

            return true;
        });
    }
}
