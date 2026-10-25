<?php

declare(strict_types=1);

namespace App\Application\Privacy;

final readonly class PrivacyProgressAuthority
{
    public function __construct(public string $requestId, public string $accountId, public int $accountVersion, public \DateTimeImmutable $expiresAt)
    {
    }
}
