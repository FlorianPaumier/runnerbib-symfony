<?php

declare(strict_types=1);

namespace App\Application\Notifications;

final readonly class NotificationPreferences
{
    public function __construct(public bool $optionalEmailEnabled, public bool $optionalInAppEnabled)
    {
    }
}
