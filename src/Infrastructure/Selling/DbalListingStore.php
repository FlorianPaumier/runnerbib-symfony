<?php

declare(strict_types=1);

namespace App\Infrastructure\Selling;

use App\Application\Availability\AvailabilityPublisher;
use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Application\Operations\OutboxMessage;
use App\Application\Operations\OutboxWriter;
use App\Application\Selling\ListingStore;
use App\Domain\Finance\FeePolicy;
use App\Domain\Selling\PublicationEligibility;
use Doctrine\DBAL\Connection;

final readonly class DbalListingStore implements ListingStore
{
    public function __construct(private Connection $database, private AvailabilityPublisher $availability, private string $providerScope, private AuditWriter $audit, private OutboxWriter $outbox)
    {
    }

    public function draft(string $sellerId, string $listingId, string $entitlementId, int $priceCents): bool
    {
        (new FeePolicy())->quote($priceCents);

        return $this->database->transactional(function () use ($sellerId, $listingId, $entitlementId, $priceCents): bool {
            $raceId = $this->database->fetchOne('SELECT race_id FROM bib_entitlements WHERE id=?', [$entitlementId]);
            if (!is_string($raceId)) {
                return false;
            }
            $this->database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [$raceId]);
            $owner = $this->database->fetchOne('SELECT current_owner_account_id FROM bib_entitlements WHERE id=? FOR UPDATE', [$entitlementId]);
            if ($owner !== $sellerId || true !== $this->database->fetchOne("SELECT EXISTS(SELECT 1 FROM accounts WHERE id=? AND lifecycle_state='active' AND verified_at IS NOT NULL)", [$sellerId])) {
                return false;
            }

            $inserted = 1 === $this->database->executeStatement('INSERT INTO resale_listings(id,race_id,entitlement_id,seller_id,price_cents) VALUES (?,?,?,?,?) ON CONFLICT(id) DO NOTHING', [$listingId, $raceId, $entitlementId, $sellerId, $priceCents]);
            if ($inserted) {
                $this->record($sellerId, $listingId, 'listing.drafted', 1, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }

            return $inserted;
        });
    }

    public function attachProof(string $sellerId, string $listingId, string $proofId, int $expectedVersion): bool
    {
        return $this->database->transactional(function () use ($sellerId, $listingId, $proofId, $expectedVersion): bool {
            $raceId = $this->database->fetchOne('SELECT race_id FROM resale_listings WHERE id=?', [$listingId]);
            if (!is_string($raceId)) {
                return false;
            }
            $this->database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [$raceId]);
            $listing = $this->database->fetchAssociative('SELECT seller_id,state,version,entitlement_id FROM resale_listings WHERE id=? FOR UPDATE', [$listingId]);
            if (false === $listing || $sellerId !== $listing['seller_id'] || 'draft' !== $listing['state'] || $expectedVersion !== $listing['version']) {
                return false;
            }
            $proof = $this->database->fetchAssociative("SELECT p.proof_version FROM ownership_proofs p JOIN bib_entitlements e ON e.id=p.entitlement_id JOIN accounts a ON a.id=p.submitted_by WHERE p.id=? AND p.entitlement_id=? AND p.submitted_by=? AND p.decision='approved' AND p.reviewed_version=p.proof_version AND e.current_owner_account_id=? AND a.lifecycle_state='active' AND a.verified_at IS NOT NULL FOR UPDATE OF p,e,a", [$proofId, $listing['entitlement_id'], $sellerId, $sellerId]);
            if (false === $proof) {
                return false;
            }
            $this->database->executeStatement('UPDATE resale_listings SET proof_id=?,approved_proof_version=?,version=version+1 WHERE id=?', [$proofId, $proof['proof_version'], $listingId]);
            $this->record($sellerId, $listingId, 'listing.proof_attached', $expectedVersion + 1, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            return true;
        });
    }

    public function reprice(string $sellerId, string $listingId, int $priceCents, int $expectedVersion, \DateTimeImmutable $now): bool
    {
        return $this->offer($sellerId, $listingId, $priceCents, $expectedVersion, $now);
    }

    public function publish(string $sellerId, string $listingId, int $expectedVersion, \DateTimeImmutable $now): bool
    {
        return $this->offer($sellerId, $listingId, null, $expectedVersion, $now);
    }

    public function withdraw(string $sellerId, string $listingId, int $expectedVersion, \DateTimeImmutable $now): bool
    {
        return $this->database->transactional(function () use ($sellerId, $listingId, $expectedVersion, $now): bool {
            $raceId = $this->database->fetchOne('SELECT race_id FROM resale_listings WHERE id=? AND seller_id=?', [$listingId, $sellerId]);
            if (!is_string($raceId)) {
                return false;
            }
            $checkoutId = $this->database->fetchOne("SELECT checkout_id::text FROM checkout_items WHERE listing_id=? AND protection_state='protected'", [$listingId]);
            $this->database->fetchFirstColumn('SELECT id FROM races WHERE id=? OR id IN (SELECT race_id FROM checkout_items WHERE checkout_id=?) ORDER BY id FOR UPDATE', [$raceId, is_string($checkoutId) ? $checkoutId : null]);
            $currentCheckout = $this->database->fetchOne("SELECT checkout_id::text FROM checkout_items WHERE listing_id=? AND protection_state='protected'", [$listingId]);
            if ($currentCheckout !== $checkoutId) {
                return false;
            }
            $listing = $this->database->fetchAssociative('SELECT seller_id,state,version FROM resale_listings WHERE id=? FOR UPDATE', [$listingId]);
            if (false === $listing || $listing['seller_id'] !== $sellerId || $listing['version'] !== $expectedVersion || !in_array($listing['state'], ['draft', 'published'], true)) {
                return false;
            }
            $active = $this->database->fetchOne("SELECT EXISTS(SELECT 1 FROM accounts WHERE id=? AND lifecycle_state='active')", [$sellerId]);
            if (true !== $active) {
                return false;
            }
            if (is_string($checkoutId)) {
                $checkout = $this->database->fetchAssociative("SELECT c.state,c.version,c.cancellation_requested_at,o.id::text AS operation_id FROM checkouts c JOIN financial_operations o ON o.checkout_id=c.id AND o.kind='checkout_payment' WHERE c.id=? FOR UPDATE OF c", [$checkoutId]);
                if (false === $checkout || !in_array($checkout['state'], ['pending', 'processing', 'unknown'], true)) {
                    return false;
                }
                if (null === $checkout['cancellation_requested_at']) {
                    if (!is_int($checkout['version']) || !is_string($checkout['operation_id'])) {
                        throw new \UnexpectedValueException('Invalid checkout cancellation context.');
                    }
                    $this->database->executeStatement('UPDATE checkouts SET cancellation_requested_at=?,version=version+1 WHERE id=?', [$now->format(DATE_ATOM), $checkoutId]);
                    $eventId = $this->database->fetchOne('SELECT gen_random_uuid()::text');
                    if (!is_string($eventId)) {
                        throw new \RuntimeException('Unable to allocate cancellation identity.');
                    }
                    $this->outbox->append(new OutboxMessage($eventId, 'checkout:'.$checkoutId.':cancellation-requested', 'checkout', $checkoutId, $checkout['version'] + 1, 'checkout.cancellation_requested', 1, json_encode(['operation_id' => $checkout['operation_id']], JSON_THROW_ON_ERROR), $now));
                }
            }
            $this->database->executeStatement("UPDATE resale_listings SET state='withdrawn',withdrawn_at=?,version=version+1 WHERE id=?", [$now->format('Y-m-d H:i:s.uP'), $listingId]);
            $this->availability->invalidate($listingId, $now, 'withdrawn');
            $this->record($sellerId, $listingId, 'listing.withdrawn', $expectedVersion + 1, $now);

            return true;
        });
    }

    private function offer(string $sellerId, string $listingId, ?int $priceCents, int $expectedVersion, \DateTimeImmutable $now): bool
    {
        if ('' === trim($this->providerScope) || 'unconfigured' === $this->providerScope) {
            return false;
        }

        return $this->database->transactional(function () use ($sellerId, $listingId, $priceCents, $expectedVersion, $now): bool {
            $raceId = $this->database->fetchOne('SELECT race_id FROM resale_listings WHERE id=?', [$listingId]);
            if (!is_string($raceId)) {
                return false;
            }
            $this->database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [$raceId]);
            $listing = $this->database->fetchAssociative('SELECT seller_id,state,version,price_cents,entitlement_id FROM resale_listings WHERE id=? FOR UPDATE', [$listingId]);
            if (false === $listing || $listing['seller_id'] !== $sellerId || $listing['version'] !== $expectedVersion || (null === $priceCents ? 'draft' : 'published') !== $listing['state']) {
                return false;
            }
            $otherPublished = $this->database->fetchOne("SELECT EXISTS(SELECT 1 FROM resale_listings WHERE entitlement_id=? AND state='published' AND id<>?)", [$listing['entitlement_id'], $listingId]);
            if (true === $otherPublished) {
                return false;
            }
            $publishing = null === $priceCents;
            if ($publishing) {
                if (!is_int($listing['price_cents']) && !is_string($listing['price_cents'])) {
                    throw new \UnexpectedValueException('Invalid stored listing price.');
                }
                $priceCents = (int) $listing['price_cents'];
            }
            $protected = $this->database->fetchOne("SELECT EXISTS(SELECT 1 FROM checkout_items WHERE entitlement_id=? AND protection_state='protected')", [$listing['entitlement_id']]);
            if (true === $protected) {
                return false;
            }
            $context = $this->database->fetchAssociative("SELECT a.lifecycle_state,a.verified_at,e.current_owner_account_id,p.decision,p.proof_version,p.reviewed_version,l.approved_proof_version,r.publication_state,r.resale_deadline,r.min_price_cents,r.max_price_cents,s.readiness_state FROM resale_listings l JOIN accounts a ON a.id=l.seller_id JOIN bib_entitlements e ON e.id=l.entitlement_id JOIN ownership_proofs p ON p.id=l.proof_id JOIN races r ON r.id=l.race_id JOIN seller_accounts s ON s.seller_id=l.seller_id WHERE l.id=? AND s.provider='stripe' AND s.provider_scope=? AND s.last_reconciled_at IS NOT NULL FOR UPDATE OF a,e,p,s", [$listingId, $this->providerScope]);
            if (false === $context) {
                return false;
            }
            $deadline = $context['resale_deadline'];
            if (!is_string($deadline) && null !== $deadline) {
                throw new \UnexpectedValueException('Invalid stored resale deadline.');
            }
            foreach (['min_price_cents', 'max_price_cents'] as $key) {
                if (!is_int($context[$key]) && !is_string($context[$key]) && null !== $context[$key]) {
                    throw new \UnexpectedValueException('Invalid stored resale bounds.');
                }
            }
            $eligibility = new PublicationEligibility('active' === $context['lifecycle_state'], null !== $context['verified_at'], $sellerId === $context['current_owner_account_id'], 'approved' === $context['decision'] && $context['proof_version'] === $context['reviewed_version'] && $context['proof_version'] === $context['approved_proof_version'], 'ready' === $context['readiness_state'], null === $deadline ? null : new \DateTimeImmutable($deadline), null === $context['min_price_cents'] ? null : (int) $context['min_price_cents'], null === $context['max_price_cents'] ? null : (int) $context['max_price_cents'], 'published' === $context['publication_state']);
            if (!$eligibility->allows($priceCents, $now)) {
                return false;
            }
            (new FeePolicy())->quote($priceCents);
            if ($publishing) {
                $this->database->executeStatement("UPDATE resale_listings SET state='published',published_at=?,version=version+1 WHERE id=?", [$now->format('Y-m-d H:i:s.uP'), $listingId]);
            } else {
                $this->database->executeStatement('UPDATE resale_listings SET price_cents=?,offer_version=offer_version+1,version=version+1 WHERE id=?', [$priceCents, $listingId]);
            }
            $eventId = $this->database->fetchOne('SELECT gen_random_uuid()::text');
            if (!is_string($eventId)) {
                throw new \RuntimeException('Unable to allocate availability identity.');
            }
            $this->availability->publish($eventId, $listingId, $now);
            $this->record($sellerId, $listingId, $publishing ? 'listing.published' : 'listing.repriced', $expectedVersion + 1, $now);

            return true;
        });
    }

    private function record(string $sellerId, string $listingId, string $action, int $version, \DateTimeImmutable $now): void
    {
        $id = $this->database->fetchOne('SELECT gen_random_uuid()::text');
        if (!is_string($id)) {
            throw new \RuntimeException('Unable to allocate listing audit identity.');
        }
        $this->audit->append(new AuditEntry($id, $sellerId, null, json_encode(['scope' => 'current_owner', 'listing_version' => $version], JSON_THROW_ON_ERROR), $action, 'listing', $listingId, null, $now, 'completed'));
    }
}
