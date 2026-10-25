<?php

declare(strict_types=1);

namespace App\Application\Availability;

use App\Domain\Availability\WaitlistMembership;

interface WaitlistStore
{
    public function requireEligibleAccount(string $accountId): void;

    public function coordinateRace(string $raceId): void;

    public function lockPublishedRace(string $raceId): void;

    public function active(string $accountId, string $raceId): ?WaitlistMembership;

    public function owned(string $accountId, string $membershipId): ?WaitlistMembership;

    public function changePreferences(string $membershipId, bool $emailEnabled, bool $inAppEnabled): void;

    public function end(string $membershipId, \DateTimeImmutable $now): bool;

    public function add(WaitlistMembership $membership, \DateTimeImmutable $now): void;
}
