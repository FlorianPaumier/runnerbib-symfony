<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

final readonly class FiveMinuteBatch
{
    public function nextAfter(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->setTimestamp((intdiv($now->getTimestamp(), 300) + 1) * 300);
    }
}
