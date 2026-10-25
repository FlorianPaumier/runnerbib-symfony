<?php

declare(strict_types=1);

namespace App\Application\Identity;

interface IdentityStore
{
    public function register(string $id, string $email, string $passwordHash, string $termsVersion, \DateTimeImmutable $now): bool;

    /** @return array{id: string, password_hash: ?string, roles: list<string>, verified_at: ?string, version: int}|null */
    public function findActive(string $email): ?array;

    public function issueCredential(string $id, string $accountId, string $purpose, string $tokenHash, \DateTimeImmutable $now, \DateTimeImmutable $expiry): bool;

    public function consumeCredential(string $tokenHash, string $purpose, \DateTimeImmutable $now, ?string $newPasswordHash): bool;
}
