<?php

declare(strict_types=1);

namespace App\Application\Availability;

interface AvailabilitySnapshotStore
{
    public function capture(string $eventId, string $listingId, \DateTimeImmutable $availableAt): CapturedAvailability;

    public function invalidate(string $listingId, \DateTimeImmutable $now, string $reason): void;
}
