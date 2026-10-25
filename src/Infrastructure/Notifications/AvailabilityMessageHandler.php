<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\DeliverAvailabilityDigest;
use App\Application\Notifications\ScheduleAvailabilityBatch;
use App\Application\Operations\OutboxEventHandler;
use App\Application\Operations\OutboxMessage;

final readonly class AvailabilityMessageHandler implements OutboxEventHandler
{
    public function __construct(private ScheduleAvailabilityBatch $scheduler, private DeliverAvailabilityDigest $delivery)
    {
    }

    public function supports(string $eventType): bool
    {
        return in_array($eventType, ['availability.batch_ready', 'notifications.availability_digest'], true);
    }

    public function __invoke(OutboxMessage $message): void
    {
        if (!$this->supports($message->eventType) || 1 !== $message->payloadVersion) {
            throw new \InvalidArgumentException('Invalid availability message.');
        }
        try {
            $payload = json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Invalid availability message.');
        }
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid availability message.');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ('availability.batch_ready' === $message->eventType) {
            if (!is_string($payload['event_id'] ?? null) || !self::uuid($payload['event_id']) || $payload['event_id'] !== $message->aggregateId) {
                throw new \InvalidArgumentException('Invalid availability message.');
            }
            $this->scheduler->schedule($payload['event_id'], $message->id, $now);

            return;
        }
        if (!is_string($payload['recipient_id'] ?? null) || !self::uuid($payload['recipient_id']) || !is_array($payload['notification_ids'] ?? null) || !array_is_list($payload['notification_ids']) || [] === $payload['notification_ids'] || count($payload['notification_ids']) > 100) {
            throw new \InvalidArgumentException('Invalid availability message.');
        }
        $ids = [];
        foreach ($payload['notification_ids'] as $id) {
            if (!is_string($id) || !self::uuid($id)) {
                throw new \InvalidArgumentException('Invalid availability message.');
            }
            $ids[] = $id;
        }
        $this->delivery->deliver($message->effectKey, $payload['recipient_id'], $ids, $now);
    }

    private static function uuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value);
    }
}
