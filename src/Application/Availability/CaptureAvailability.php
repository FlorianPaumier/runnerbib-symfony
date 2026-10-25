<?php

declare(strict_types=1);

namespace App\Application\Availability;

use App\Application\Operations\OutboxMessage;
use App\Application\Operations\OutboxWriter;

final readonly class CaptureAvailability implements AvailabilityPublisher
{
    public function __construct(private AvailabilitySnapshotStore $store, private OutboxWriter $outbox)
    {
    }

    public function publish(string $eventId, string $listingId, \DateTimeImmutable $availableAt): AvailabilityOfferView
    {
        $captured = $this->store->capture($eventId, $listingId, $availableAt);
        if ($captured->created) {
            $offer = $captured->offer;
            $this->outbox->append(new OutboxMessage($offer->eventId, 'availability:'.$offer->eventId, 'availability_event', $offer->eventId, 1, 'availability.batch_ready', 1, json_encode(['event_id' => $offer->eventId, 'listing_id' => $offer->listingId, 'race_id' => $offer->raceId], JSON_THROW_ON_ERROR), $offer->availableAt));
        }

        return $captured->offer;
    }

    public function invalidate(string $listingId, \DateTimeImmutable $now, string $reason): void
    {
        if (!in_array($reason, ['repriced', 'withdrawn', 'sold'], true)) {
            throw new \InvalidArgumentException('Invalid offer invalidation reason.');
        }
        $this->store->invalidate($listingId, $now, $reason);
    }
}
