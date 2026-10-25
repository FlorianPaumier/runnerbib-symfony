<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Operations\TransactionManager;

final readonly class RecipientInbox
{
    public function __construct(private NotificationStore $store, private TransactionManager $transactions, private NotificationPreferenceStore $preferences)
    {
    }

    public function record(string $id, string $recipientId, string $eventKey, string $category, string $privatePayload, \DateTimeImmutable $now): void
    {
        if (!in_array($category, ['account', 'payment', 'marketing'], true)) {
            throw new \InvalidArgumentException('Invalid notification category.');
        }
        $this->validate($eventKey, $privatePayload);
        $this->transactions->run(function () use ($id, $recipientId, $eventKey, $category, $privatePayload, $now): void {
            $preferences = $this->preferences->preferences($recipientId);
            $essential = in_array($category, ['account', 'payment'], true);
            $this->store->record($id, $recipientId, $eventKey, $category, $privatePayload, $now, $essential || $preferences->optionalEmailEnabled, $essential || $preferences->optionalInAppEnabled);
        });
    }

    public function recordAvailability(string $id, string $recipientId, string $eventKey, string $privatePayload, \DateTimeImmutable $now, bool $emailEnabled, bool $inAppEnabled): void
    {
        $this->validate($eventKey, $privatePayload);
        $this->transactions->run(function () use ($id, $recipientId, $eventKey, $privatePayload, $now, $emailEnabled, $inAppEnabled): void {
            $this->preferences->preferences($recipientId);
            $this->store->record($id, $recipientId, $eventKey, 'availability', $privatePayload, $now, $emailEnabled, $inAppEnabled);
        });
    }

    public function read(string $accountId, string $notificationId, \DateTimeImmutable $now): void
    {
        if (!$this->store->read($accountId, $notificationId, $now)) {
            throw new \DomainException('Notification is unavailable.');
        }
    }

    public function outcome(string $notificationId, string $channel, string $outcome): void
    {
        if (!in_array($channel, ['email', 'in_app'], true) || !in_array($outcome, ['accepted', 'failed', 'unknown', 'skipped'], true)) {
            throw new \InvalidArgumentException('Invalid channel outcome.');
        }
        if (!$this->store->outcome($notificationId, $channel, $outcome)) {
            throw new \DomainException('Delivery outcome cannot change.');
        }
    }

    private function validate(string $eventKey, string $privatePayload): void
    {
        if ('' === trim($eventKey)) {
            throw new \InvalidArgumentException('Event key is required.');
        }
        try {
            $payload = json_decode($privatePayload, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Invalid private payload.');
        }
        if (!$payload instanceof \stdClass) {
            throw new \InvalidArgumentException('Private payload must be an object.');
        }
    }

    /** @return list<NotificationView> */
    public function messages(string $accountId, ?InboxCursor $before = null, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Inbox limit must be between 1 and 100.');
        }

        return $this->store->messages($accountId, $before, $limit);
    }
}
