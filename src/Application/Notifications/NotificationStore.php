<?php

declare(strict_types=1);

namespace App\Application\Notifications;

interface NotificationStore
{
    public function record(string $id, string $recipientId, string $eventKey, string $category, string $privatePayload, \DateTimeImmutable $now, bool $emailEnabled, bool $inAppEnabled): void;

    public function read(string $accountId, string $notificationId, \DateTimeImmutable $now): bool;

    public function outcome(string $notificationId, string $channel, string $outcome): bool;

    /** @return list<NotificationView> */
    public function messages(string $accountId, ?InboxCursor $before = null, int $limit = 50): array;
}
