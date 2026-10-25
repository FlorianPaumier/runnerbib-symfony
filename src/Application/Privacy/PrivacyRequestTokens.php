<?php

declare(strict_types=1);

namespace App\Application\Privacy;

interface PrivacyRequestTokens
{
    public function issue(string $requestId, string $accountId, int $accountVersion, \DateTimeImmutable $expiresAt): string;

    public function verify(string $token, \DateTimeImmutable $now): PrivacyProgressAuthority;
}
