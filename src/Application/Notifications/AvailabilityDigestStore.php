<?php

declare(strict_types=1);

namespace App\Application\Notifications;

interface AvailabilityDigestStore
{
    public function outcome(AvailabilityDigest $digest, string $state): void;

    /** @param list<string> $notificationIds */
    public function prepare(string $recipientId, array $notificationIds, \DateTimeImmutable $now): ?AvailabilityDigest;
}
