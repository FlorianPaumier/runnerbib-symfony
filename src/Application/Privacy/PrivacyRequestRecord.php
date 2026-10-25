<?php

declare(strict_types=1);

namespace App\Application\Privacy;

final readonly class PrivacyRequestRecord
{
    /** @param list<string> $blockers */
    public function __construct(public string $requestId, public string $accountId, public string $kind, public string $state, public int $accountVersion, public \DateTimeImmutable $authorityExpiresAt, public array $blockers)
    {
    }
}
