<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Application\Notifications\AvailabilityDigest;
use App\Application\Notifications\AvailabilityDigestSender;

final class ControlledAvailabilityMail implements AvailabilityDigestSender
{
    /** @var list<AvailabilityDigest> */
    public array $sent = [];
    public bool $fail = false;

    public function send(AvailabilityDigest $digest, string $messageId): void
    {
        $this->sent[] = $digest;
        if ($this->fail) {
            throw new \RuntimeException('Private provider details must not escape.');
        }
    }
}
