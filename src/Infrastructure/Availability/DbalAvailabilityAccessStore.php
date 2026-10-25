<?php

declare(strict_types=1);

namespace App\Infrastructure\Availability;

use App\Application\Availability\AvailabilityAccessFacts;
use App\Application\Availability\AvailabilityAccessStore;
use Doctrine\DBAL\Connection;

final readonly class DbalAvailabilityAccessStore implements AvailabilityAccessStore
{
    private const OFFER_COLUMNS = <<<'SQL'
SELECT offer.id::text,offer.listing_id::text,offer.race_id::text,offer.offer_version::text,offer.price_cents::text,offer.currency,offer.available_at::text,offer.priority_ends_at::text,offer.recipient_count::text,offer.invalidated_at::text,r.resale_deadline::text,buyer.id::text AS buyer_id,
COALESCE((offer.invalidated_at IS NULL AND l.state='published' AND r.publication_state='published' AND l.offer_version=offer.offer_version AND l.price_cents=offer.price_cents AND l.currency=offer.currency AND e.current_owner_account_id=l.seller_id AND p.decision='approved' AND p.proof_version=l.approved_proof_version AND p.reviewed_version=l.approved_proof_version AND p.entitlement_id=l.entitlement_id),false) AS current,
EXISTS(SELECT 1 FROM availability_recipients ar WHERE ar.event_id=offer.id AND ar.account_id=buyer.id) AS snapshot_member,
EXISTS(SELECT 1 FROM checkout_items ci WHERE ci.entitlement_id=l.entitlement_id AND ci.protection_state='protected') AS checkout_protected,
(buyer.id IS NOT NULL AND buyer.lifecycle_state='active' AND buyer.verified_at IS NOT NULL AND NOT EXISTS(SELECT 1 FROM bib_entitlements owned WHERE owned.race_id=offer.race_id AND owned.current_owner_account_id=buyer.id) AND NOT EXISTS(SELECT 1 FROM buyer_race_guards g WHERE g.buyer_id=buyer.id AND g.race_id=offer.race_id AND g.reserved_checkout_id IS NOT NULL)) AS account_eligible
FROM offer JOIN resale_listings l ON l.id=offer.listing_id JOIN races r ON r.id=offer.race_id LEFT JOIN bib_entitlements e ON e.id=l.entitlement_id LEFT JOIN ownership_proofs p ON p.id=l.proof_id LEFT JOIN accounts buyer ON buyer.id IN (SELECT id FROM recipients)
SQL;

    public function __construct(private Connection $database)
    {
    }

    public function forListing(string $listingId, ?string $accountId): ?AvailabilityAccessFacts
    {
        $facts = $this->forListings([$listingId], $accountId);

        return $facts[$listingId] ?? null;
    }

    public function forListings(array $listingIds, ?string $accountId): array
    {
        if ([] === $listingIds) {
            return [];
        }
        $facts = [];
        $parameters = ['listings' => json_encode(array_map(static fn (string $listingId): array => ['id' => $listingId], $listingIds), JSON_THROW_ON_ERROR)];
        foreach ($this->access('offer AS (SELECT DISTINCT ON (a.listing_id) a.* FROM availability_events a WHERE a.listing_id IN (SELECT id FROM jsonb_to_recordset(CAST(:listings AS jsonb)) AS t(id uuid)) ORDER BY a.listing_id,a.offer_version DESC)', $parameters, null === $accountId ? [] : [$accountId]) as $row) {
            $listingId = $row['listing_id'] ?? null;
            assert(is_string($listingId));
            $facts[$listingId] = $this->facts($row);
        }

        return $facts;
    }

    public function forEvent(string $eventId, ?string $accountId): ?AvailabilityAccessFacts
    {
        $rows = $this->access('offer AS (SELECT a.* FROM availability_events a WHERE a.id = :id ORDER BY a.offer_version DESC LIMIT 1)', ['id' => $eventId], null === $accountId ? [] : [$accountId]);
        if ([] === $rows) {
            return null;
        }

        return $this->facts($rows[0]);
    }

    public function forEventRecipients(string $eventId, array $accountIds): array
    {
        $facts = [];
        foreach ($this->access('offer AS (SELECT a.* FROM availability_events a WHERE a.id = :id ORDER BY a.offer_version DESC LIMIT 1)', ['id' => $eventId], $accountIds) as $row) {
            $buyerId = $row['buyer_id'] ?? null;
            if (!is_string($buyerId)) {
                continue;
            }
            $facts[$buyerId] = $this->facts($row);
        }

        return $facts;
    }

    /**
     * @param array<string,string> $parameters
     * @param list<string>         $accountIds
     *
     * @return list<array<string,mixed>>
     */
    private function access(string $offerSource, array $parameters, array $accountIds): array
    {
        $sql = 'WITH recipients AS (SELECT id FROM jsonb_to_recordset(CAST(:accounts AS jsonb)) AS t(id uuid)), '.$offerSource."\n".self::OFFER_COLUMNS;
        $parameters['accounts'] = json_encode(array_map(static fn (string $accountId): array => ['id' => $accountId], $accountIds), JSON_THROW_ON_ERROR);

        return $this->database->fetchAllAssociative($sql, $parameters);
    }

    /** @param array<string,mixed> $row */
    private function facts(array $row): AvailabilityAccessFacts
    {
        $listingId = $row['listing_id'] ?? null;
        $raceId = $row['race_id'] ?? null;
        assert(is_string($listingId));
        assert(is_string($raceId));
        $current = $row['current'] ?? null;
        $snapshotMember = $row['snapshot_member'] ?? null;
        $accountEligible = $row['account_eligible'] ?? null;
        $checkoutProtected = $row['checkout_protected'] ?? null;
        $resaleDeadline = $row['resale_deadline'] ?? null;
        if (!is_bool($current) || !is_bool($snapshotMember) || !is_bool($accountEligible) || !is_bool($checkoutProtected) || (null !== $resaleDeadline && !is_string($resaleDeadline))) {
            throw new \UnexpectedValueException('Invalid stored access facts.');
        }

        return new AvailabilityAccessFacts((new AvailabilityOfferHydrator())->view($row, $listingId, $raceId), $current, $snapshotMember, $accountEligible, null === $resaleDeadline ? null : new \DateTimeImmutable($resaleDeadline), $checkoutProtected);
    }
}
