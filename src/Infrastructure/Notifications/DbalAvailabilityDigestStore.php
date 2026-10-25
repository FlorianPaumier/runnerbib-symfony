<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Availability\FindOfferAccess;
use App\Application\Notifications\AvailabilityDigest;
use App\Application\Notifications\AvailabilityDigestStore;
use App\Infrastructure\Availability\DbalAvailabilityAccessStore;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalAvailabilityDigestStore implements AvailabilityDigestStore
{
    public function __construct(private Connection $database)
    {
    }

    public function outcome(AvailabilityDigest $digest, string $state): void
    {
        if (!in_array($state, ['accepted', 'unknown'], true)) {
            throw new \InvalidArgumentException('Invalid mail outcome.');
        }
        foreach ($digest->offers as $offer) {
            $this->database->executeStatement("UPDATE notifications SET delivery_state=jsonb_set(delivery_state,'{email}',to_jsonb(?::text)) WHERE id=? AND delivery_state->>'email' IN ('pending','unknown')", [$state, $offer['notification_id']]);
        }
    }

    public function prepare(string $recipientId, array $notificationIds, \DateTimeImmutable $now): ?AvailabilityDigest
    {
        if ([] === $notificationIds || count($notificationIds) > 100) {
            throw new \InvalidArgumentException('Digest requires one to one hundred notifications.');
        }
        $email = $this->database->fetchOne("SELECT p.email FROM account_private_profiles p JOIN accounts a ON a.id=p.account_id WHERE a.id=? AND a.lifecycle_state='active' AND a.verified_at IS NOT NULL", [$recipientId]);
        $offers = [];
        $rows = $this->database->fetchAllAssociative("SELECT id::text,availability_event_id::text FROM notifications WHERE recipient_id=? AND id IN (?) AND category='availability' AND delivery_state->>'email' IN ('pending','unknown') ORDER BY id", [$recipientId, $notificationIds], [\Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::STRING]);
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['availability_event_id'])) {
                throw new \UnexpectedValueException('Invalid stored availability notification.');
            }
            $offer = (new FindOfferAccess(new DbalAvailabilityAccessStore($this->database)))->forEvent($row['availability_event_id'], $recipientId, $now);
            $enabled = $this->database->fetchOne('SELECT m.email_enabled FROM availability_recipients ar JOIN race_waitlist_memberships m ON m.id=ar.membership_id WHERE ar.event_id=? AND ar.account_id=? AND m.ended_at IS NULL', [$row['availability_event_id'], $recipientId]);
            if (!is_string($email) || null === $offer || !$offer->canInitiatePurchase || $now >= $offer->priorityEndsAt || true !== $enabled) {
                $this->database->executeStatement("UPDATE notifications SET delivery_state=jsonb_set(delivery_state,'{email}','\"skipped\"') WHERE id=? AND delivery_state->>'email'='pending'", [$row['id']]);
                continue;
            }
            $offers[] = ['notification_id' => $row['id'], 'race_id' => $offer->raceId, 'listing_id' => $offer->listingId, 'price_cents' => $offer->priceCents, 'deadline' => $offer->priorityEndsAt];
        }

        return [] === $offers || !is_string($email) ? null : new AvailabilityDigest($email, $offers);
    }
}
