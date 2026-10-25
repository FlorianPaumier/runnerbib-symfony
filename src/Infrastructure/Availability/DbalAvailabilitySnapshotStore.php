<?php

declare(strict_types=1);

namespace App\Infrastructure\Availability;

use App\Application\Availability\AvailabilityOfferView;
use App\Application\Availability\AvailabilitySnapshotStore;
use App\Application\Availability\CapturedAvailability;
use Doctrine\DBAL\Connection;

final readonly class DbalAvailabilitySnapshotStore implements AvailabilitySnapshotStore
{
    public function __construct(private Connection $database)
    {
    }

    public function capture(string $eventId, string $listingId, \DateTimeImmutable $availableAt): CapturedAvailability
    {
        $raceId = $this->lockRaceForListing($listingId);
        $listing = $this->database->fetchAssociative("SELECT offer_version::text,price_cents::text,currency FROM resale_listings WHERE id=? AND state='published' FOR UPDATE", [$listingId]);
        if (false === $listing) {
            throw new \DomainException('Listing is unavailable.');
        }
        if (!is_string($listing['offer_version']) || !is_string($listing['price_cents']) || !is_string($listing['currency'])) {
            throw new \UnexpectedValueException('Invalid stored offer.');
        }
        $prior = $this->database->fetchAssociative('SELECT id::text,offer_version::text,price_cents::text,currency,available_at::text,priority_ends_at::text,recipient_count::text,invalidated_at::text FROM availability_events WHERE listing_id=? AND offer_version=?', [$listingId, $listing['offer_version']]);
        if (false !== $prior) {
            $offer = (new AvailabilityOfferHydrator())->view($prior, $listingId, $raceId);
            if (null !== $offer->invalidatedAt || $offer->priceCents !== (int) $listing['price_cents'] || $offer->currency !== $listing['currency']) {
                throw new \DomainException('Offer version must advance.');
            }

            return new CapturedAvailability($offer, false);
        }
        $this->database->executeStatement("UPDATE availability_events SET invalidated_at=?,invalidation_reason='repriced' WHERE listing_id=? AND invalidated_at IS NULL", [$availableAt->format('Y-m-d\\TH:i:s.uP'), $listingId]);
        $count = $this->database->fetchOne('SELECT count(*)::text FROM race_waitlist_memberships WHERE race_id=? AND ended_at IS NULL', [$raceId]);
        if (!is_string($count)) {
            throw new \UnexpectedValueException('Invalid stored recipient count.');
        }
        $this->database->executeStatement("INSERT INTO availability_events(id,listing_id,race_id,offer_version,price_cents,currency,available_at,priority_ends_at,recipient_count) VALUES (?,?,?,?,?,?,?::timestamptz,?::timestamptz+INTERVAL '3600 seconds',?)", [$eventId, $listingId, $raceId, $listing['offer_version'], $listing['price_cents'], $listing['currency'], $availableAt->format('Y-m-d\\TH:i:s.uP'), $availableAt->format('Y-m-d\\TH:i:s.uP'), $count]);
        $this->database->executeStatement('INSERT INTO availability_recipients(event_id,race_id,account_id,membership_id,snapshotted_at) SELECT ?,race_id,account_id,id,? FROM race_waitlist_memberships WHERE race_id=? AND ended_at IS NULL', [$eventId, $availableAt->format('Y-m-d\\TH:i:s.uP'), $raceId]);

        return new CapturedAvailability(new AvailabilityOfferView($eventId, $listingId, $raceId, (int) $listing['offer_version'], (int) $listing['price_cents'], $listing['currency'], $availableAt, $availableAt->add(new \DateInterval('PT3600S')), (int) $count, null), true);
    }

    public function invalidate(string $listingId, \DateTimeImmutable $now, string $reason): void
    {
        $this->lockRaceForListing($listingId);
        $this->database->fetchOne('SELECT id FROM resale_listings WHERE id=? FOR UPDATE', [$listingId]);
        $this->database->executeStatement('UPDATE availability_events SET invalidated_at=?,invalidation_reason=? WHERE listing_id=? AND invalidated_at IS NULL', [$now->format('Y-m-d\\TH:i:s.uP'), $reason, $listingId]);
    }

    private function lockRaceForListing(string $listingId): string
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Availability mutation requires an owning transaction.');
        }
        $raceId = $this->database->fetchOne('SELECT race_id::text FROM resale_listings WHERE id=?', [$listingId]);
        if (!is_string($raceId)) {
            throw new \DomainException('Listing is unavailable.');
        }
        $this->database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [$raceId]);

        return $raceId;
    }
}
