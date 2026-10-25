<?php

declare(strict_types=1);

namespace App\Application\Privacy;

interface PrivacyRequestStore
{
    public function existing(string $requestId): ?PrivacyRequestRecord;

    public function lock(string $accountId, bool $deletion = false): int;

    /** @return list<array{id: string, version: int}> */
    public function unsoldListings(string $accountId): array;

    /** @return list<string> */
    public function fence(string $accountId, \DateTimeImmutable $now): array;

    public function current(PrivacyProgressAuthority $authority): ?PrivacyRequestRecord;
}
