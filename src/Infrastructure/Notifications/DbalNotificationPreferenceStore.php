<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\NotificationPreferences;
use App\Application\Notifications\NotificationPreferenceStore;
use Doctrine\DBAL\Connection;

final readonly class DbalNotificationPreferenceStore implements NotificationPreferenceStore
{
    public function __construct(private Connection $database)
    {
    }

    public function preferences(string $accountId): NotificationPreferences
    {
        $row = $this->database->fetchAssociative("SELECT optional_email_enabled,optional_in_app_enabled FROM accounts WHERE id = ? AND lifecycle_state <> 'anonymized'", [$accountId]);
        if (false === $row) {
            throw new \DomainException('Recipient is unavailable.');
        }
        if (!is_bool($row['optional_email_enabled']) || !is_bool($row['optional_in_app_enabled'])) {
            throw new \UnexpectedValueException('Invalid stored preferences.');
        }

        return new NotificationPreferences($row['optional_email_enabled'], $row['optional_in_app_enabled']);
    }
}
