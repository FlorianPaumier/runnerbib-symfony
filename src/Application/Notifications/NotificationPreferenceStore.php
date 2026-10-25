<?php

declare(strict_types=1);

namespace App\Application\Notifications;

interface NotificationPreferenceStore
{
    public function preferences(string $accountId): NotificationPreferences;
}
