<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\InboxCursor;
use App\Application\Notifications\NotificationStore;
use App\Application\Notifications\NotificationView;
use Doctrine\DBAL\Connection;

final readonly class DbalNotificationStore implements NotificationStore
{
    public function __construct(private Connection $database)
    {
    }

    public function record(string $id, string $recipientId, string $eventKey, string $category, string $privatePayload, \DateTimeImmutable $now, bool $emailEnabled, bool $inAppEnabled): void
    {
        $this->database->executeStatement('INSERT INTO notifications(id,recipient_id,event_key,category,payload_version,private_payload,created_at,delivery_state) VALUES (?,?,?,?,1,?,?,?) ON CONFLICT(recipient_id,event_key) DO NOTHING', [$id, $recipientId, $eventKey, $category, $privatePayload, $now->format(DATE_ATOM), json_encode(['email' => $emailEnabled ? 'pending' : 'skipped', 'in_app' => $inAppEnabled ? 'accepted' : 'skipped'], JSON_THROW_ON_ERROR)]);
    }

    public function read(string $accountId, string $notificationId, \DateTimeImmutable $now): bool
    {
        return 1 === $this->database->executeStatement("UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE id = ? AND recipient_id = ? AND delivery_state->>'in_app' = 'accepted'", [$now->format(DATE_ATOM), $notificationId, $accountId]);
    }

    public function outcome(string $notificationId, string $channel, string $outcome): bool
    {
        return 1 === $this->database->executeStatement("UPDATE notifications SET delivery_state = jsonb_set(delivery_state, ARRAY[?]::text[], to_jsonb(?::text)) WHERE id = ? AND (delivery_state->>? IN ('pending','failed','unknown') OR delivery_state->>? = ?)", [$channel, $outcome, $notificationId, $channel, $channel, $outcome]);
    }

    public function messages(string $accountId, ?InboxCursor $before = null, int $limit = 50): array
    {
        $messages = [];
        $sql = "SELECT id::text,category,payload_version,private_payload::text,created_at::text,read_at::text,delivery_state::text FROM notifications WHERE recipient_id = ? AND delivery_state->>'in_app' = 'accepted'";
        $parameters = [$accountId];
        $types = [\Doctrine\DBAL\ParameterType::STRING];
        if (null !== $before) {
            $sql .= ' AND (created_at,id) < (?::timestamptz,?::uuid)';
            $parameters[] = $before->createdAt->format('Y-m-d\\TH:i:s.uP');
            $parameters[] = $before->id;
            $types[] = $types[] = \Doctrine\DBAL\ParameterType::STRING;
        }
        $parameters[] = $limit;
        $types[] = \Doctrine\DBAL\ParameterType::INTEGER;
        foreach ($this->database->fetchAllAssociative($sql.' ORDER BY created_at DESC,id DESC LIMIT ?', $parameters, $types) as $row) {
            if (!is_int($row['payload_version']) || !is_string($row['id']) || !is_string($row['category']) || !is_string($row['private_payload']) || !is_string($row['created_at']) || (!is_string($row['read_at']) && null !== $row['read_at']) || !is_string($row['delivery_state'])) {
                throw new \UnexpectedValueException('Invalid stored notification.');
            }
            $state = json_decode($row['delivery_state'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($state)) {
                throw new \UnexpectedValueException('Invalid delivery state.');
            }
            $deliveryState = [];
            foreach ($state as $channel => $outcome) {
                if (!is_string($channel) || !is_string($outcome)) {
                    throw new \UnexpectedValueException('Invalid delivery outcome.');
                }
                $deliveryState[$channel] = $outcome;
            }
            $messages[] = new NotificationView($row['id'], $row['category'], $row['private_payload'], new \DateTimeImmutable($row['created_at']), null === $row['read_at'] ? null : new \DateTimeImmutable($row['read_at']), $deliveryState, $row['payload_version']);
        }

        return $messages;
    }
}
