<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Availability\OfferAccessView;

interface AvailabilityBatchStore
{
    public function runNext(\DateTimeImmutable $now): bool;

    public function lockOffer(string $eventId): void;

    public function schedule(OfferAccessView $offer, string $runId, \DateTimeImmutable $scheduledAt, \DateTimeImmutable $receivedAt): void;
}
