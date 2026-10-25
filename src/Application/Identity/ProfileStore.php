<?php

declare(strict_types=1);

namespace App\Application\Identity;

interface ProfileStore
{
    public function privateProfile(string $accountId): ?AccountProfile;

    public function publicProfile(string $accountId): ?PublicProfile;

    public function update(string $accountId, ?string $displayName, bool $optionalEmailEnabled, bool $optionalInAppEnabled, int $expectedVersion): bool;
}
