<?php

declare(strict_types=1);

namespace App\Infrastructure\Selling;

use App\Application\Selling\SellerInventoryItem;
use App\Application\Selling\SellerInventoryStore;
use Doctrine\DBAL\Connection;

final readonly class DbalSellerInventoryStore implements SellerInventoryStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function forSeller(string $sellerId): array
    {
        $rows = $this->connection->fetchAllAssociative("WITH actor AS NOT MATERIALIZED (SELECT id FROM accounts WHERE id=? AND lifecycle_state='active' AND verified_at IS NOT NULL), eligible AS (SELECT e.id FROM actor a JOIN bib_entitlements e ON e.current_owner_account_id=a.id UNION SELECT l.entitlement_id FROM actor a JOIN resale_listings l ON l.seller_id=a.id WHERE l.entitlement_id IS NOT NULL) SELECT e.id AS entitlement_id,r.id AS race_id,r.name AS race_name,e.bib_number,COALESCE(e.current_owner_account_id=a.id,false) AS owns_entitlement,l.id AS listing_id,l.version AS listing_version,l.price_cents,l.state AS listing_state,p.id AS proof_id,p.decision AS proof_decision,p.proof_version,p.reviewed_version,EXISTS(SELECT 1 FROM checkout_items i WHERE i.entitlement_id=e.id AND i.protection_state='protected') AS checkout_protected,COALESCE((SELECT jsonb_agg(jsonb_build_object('id',proof.id,'submittedAt',proof.submitted_at) ORDER BY proof.submitted_at,proof.id) FROM ownership_proofs proof WHERE proof.entitlement_id=e.id AND proof.submitted_by=a.id AND e.current_owner_account_id=a.id AND proof.decision='approved' AND proof.reviewed_version=proof.proof_version),'[]'::jsonb)::text AS approved_proofs FROM actor a JOIN eligible x ON true JOIN bib_entitlements e ON e.id=x.id JOIN races r ON r.id=e.race_id LEFT JOIN resale_listings l ON l.entitlement_id=e.id AND l.seller_id=a.id LEFT JOIN ownership_proofs p ON p.id=l.proof_id AND p.submitted_by=a.id ORDER BY r.id,e.bib_number,e.id,l.id", [$sellerId]);
        $items = [];
        foreach ($rows as $row) {
            if (!is_string($row['entitlement_id']) || !is_string($row['race_id']) || !is_string($row['race_name']) || !is_string($row['bib_number']) || !is_bool($row['owns_entitlement'])
                || !(is_string($row['listing_id']) || null === $row['listing_id']) || !(is_int($row['listing_version']) || null === $row['listing_version']) || !(is_int($row['price_cents']) || null === $row['price_cents']) || !(is_string($row['listing_state']) || null === $row['listing_state'])
                || !(is_string($row['proof_id']) || null === $row['proof_id']) || !(is_string($row['proof_decision']) || null === $row['proof_decision']) || !(is_int($row['proof_version']) || null === $row['proof_version']) || !(is_int($row['reviewed_version']) || null === $row['reviewed_version']) || !is_bool($row['checkout_protected']) || !is_string($row['approved_proofs'])) {
                throw new \UnexpectedValueException('Invalid stored seller inventory.');
            }
            $proofs = json_decode($row['approved_proofs'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($proofs) || !array_is_list($proofs)) {
                throw new \UnexpectedValueException('Invalid stored seller proofs.');
            }
            $approvedProofs = [];
            foreach ($proofs as $proof) {
                if (!is_array($proof) || !is_string($proof['id'] ?? null) || !is_string($proof['submittedAt'] ?? null)) {
                    throw new \UnexpectedValueException('Invalid stored seller proof.');
                }
                $approvedProofs[] = ['id' => $proof['id'], 'submittedAt' => $proof['submittedAt']];
            }
            $items[] = new SellerInventoryItem($row['entitlement_id'], $row['race_id'], $row['race_name'], $row['bib_number'], $row['owns_entitlement'], $row['listing_id'], $row['listing_version'], $row['price_cents'], $row['listing_state'], $row['proof_id'], $row['proof_decision'], $row['proof_version'], $row['reviewed_version'], $row['checkout_protected'], $approvedProofs);
        }

        return $items;
    }
}
