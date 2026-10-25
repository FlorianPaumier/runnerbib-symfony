<?php

declare(strict_types=1);

namespace App\Application\Notifications;

interface AvailabilityDigestSender
{
    public function send(AvailabilityDigest $digest, string $messageId): void;
}
