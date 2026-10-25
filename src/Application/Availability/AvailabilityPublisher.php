<?php

declare(strict_types=1);

namespace App\Application\Availability;

interface AvailabilityPublisher
{
    public function publish(string $eventId, string $listingId, \DateTimeImmutable $availableAt): AvailabilityOfferView;

    public function invalidate(string $listingId, \DateTimeImmutable $now, string $reason): void;
}
