<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Availability\FindOfferAccess;
use App\Application\Operations\EffectConsumer;
use App\Domain\Notifications\FiveMinuteBatch;

final readonly class ScheduleAvailabilityBatch
{
    public function __construct(private FindOfferAccess $access, private AvailabilityBatchStore $store, private EffectConsumer $consumer)
    {
    }

    public function schedule(string $eventId, string $runId, \DateTimeImmutable $receivedAt): bool
    {
        return $this->consumer->consume('notifications.schedule_availability', 'availability:'.$eventId, $receivedAt, function () use ($eventId, $runId, $receivedAt): void {
            $this->store->lockOffer($eventId);
            $offer = $this->access->forEvent($eventId, null, $receivedAt);
            if (null === $offer) {
                throw new \DomainException('Availability event is unavailable.');
            }
            $scheduledAt = (new FiveMinuteBatch())->nextAfter($receivedAt);
            if ('unavailable' === $offer->accessState || $scheduledAt >= $offer->priorityEndsAt || (null !== $offer->resaleDeadline && $scheduledAt >= $offer->resaleDeadline)) {
                return;
            }
            $this->store->schedule($offer, $runId, $scheduledAt, $receivedAt);
        });
    }
}
