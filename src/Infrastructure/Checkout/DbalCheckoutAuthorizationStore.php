<?php

declare(strict_types=1);

namespace App\Infrastructure\Checkout;

use App\Application\Checkout\CheckoutAuthorizationStore;
use Doctrine\DBAL\Connection;

final readonly class DbalCheckoutAuthorizationStore implements CheckoutAuthorizationStore
{
    public function __construct(private Connection $database)
    {
    }

    public function unreadySellers(string $buyerId, string $scope): array
    {
        $sellers = $this->database->fetchFirstColumn("SELECT DISTINCT l.seller_id::text FROM cart_items c JOIN resale_listings l ON l.id=c.listing_id JOIN seller_accounts s ON s.seller_id=l.seller_id AND s.provider='stripe' AND s.provider_scope=? WHERE c.buyer_id=? AND s.readiness_state<>'ready' ORDER BY l.seller_id::text", [$scope, $buyerId]);
        foreach ($sellers as $seller) {
            assert(is_string($seller));
        }

        return $sellers;
    }

    public function lockCart(string $buyerId, string $scope): array
    {
        $races = $this->database->fetchFirstColumn('SELECT DISTINCT l.race_id::text FROM cart_items c JOIN resale_listings l ON l.id=c.listing_id WHERE c.buyer_id=? ORDER BY l.race_id::text', [$buyerId]);
        foreach ($races as $race) {
            $this->database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [$race]);
        }
        (new DbalCartStore($this->database))->coordinateBuyer($buyerId);
        $current = $this->database->fetchFirstColumn('SELECT DISTINCT l.race_id::text FROM cart_items c JOIN resale_listings l ON l.id=c.listing_id WHERE c.buyer_id=? ORDER BY l.race_id::text', [$buyerId]);
        if ($races !== $current) {
            throw new \DomainException('Cart changed during authorization; retry.');
        }
        foreach ($races as $race) {
            $this->database->fetchOne('SELECT reserved_checkout_id FROM buyer_race_guards WHERE buyer_id=? AND race_id=? FOR UPDATE', [$buyerId, $race]);
        }

        $rows = $this->database->fetchAllAssociative("SELECT l.id::text,l.race_id::text,l.entitlement_id::text,l.seller_id::text,l.price_cents,r.participation,r.registration_schema,r.registration_schema_version FROM cart_items c JOIN resale_listings l ON l.id=c.listing_id JOIN races r ON r.id=l.race_id JOIN bib_entitlements e ON e.id=l.entitlement_id JOIN accounts seller ON seller.id=l.seller_id JOIN seller_accounts s ON s.seller_id=l.seller_id AND s.provider='stripe' AND s.provider_scope=? WHERE c.buyer_id=? AND seller.lifecycle_state='active' AND seller.verified_at IS NOT NULL AND s.readiness_state='ready' ORDER BY r.id,e.id,l.id FOR UPDATE OF e,l,s,seller", [$scope, $buyerId]);
        if (count($rows) !== $this->database->fetchOne('SELECT COUNT(*) FROM cart_items WHERE buyer_id=?', [$buyerId]) || [] === $rows) {
            throw new \DomainException('Complete cart is unavailable.');
        }

        return array_map(static function (array $row): \App\Application\Checkout\CheckoutOffer {
            foreach (['id', 'race_id', 'entitlement_id', 'seller_id', 'participation'] as $key) {
                if (!is_string($row[$key])) {
                    throw new \UnexpectedValueException('Invalid checkout terms.');
                }
            }
            $schema = null;
            if (null !== $row['registration_schema']) {
                if (!is_string($row['registration_schema'])) {
                    throw new \UnexpectedValueException('Invalid registration schema.');
                }
                $schema = json_decode($row['registration_schema'], false, 512, JSON_THROW_ON_ERROR);
                if (!$schema instanceof \stdClass) {
                    throw new \UnexpectedValueException('Invalid registration schema.');
                }
            }
            if (null !== $row['registration_schema_version'] && !is_int($row['registration_schema_version'])) {
                throw new \UnexpectedValueException('Invalid schema version.');
            }

            return new \App\Application\Checkout\CheckoutOffer($row['id'], $row['race_id'], $row['entitlement_id'], $row['seller_id'], $row['participation'], $row['registration_schema_version'], $schema);
        }, $rows);
    }

    public function identity(string $buyerId): array
    {
        $row = $this->database->fetchAssociative('SELECT display_name,email FROM account_private_profiles WHERE account_id=?', [$buyerId]);
        if (false === $row || !is_string($row['display_name']) || '' === trim($row['display_name']) || !is_string($row['email'])) {
            throw new \DomainException('Buyer identity is incomplete.');
        }

        return $row;
    }

    public function id(): string
    {
        $id = $this->database->fetchOne('SELECT gen_random_uuid()::text');
        if (!is_string($id)) {
            throw new \RuntimeException('Cannot generate checkout identity.');
        }

        return $id;
    }

    public function commit(\App\Application\Checkout\CheckoutAuthorizationView $checkout, string $buyerId, \DateTimeImmutable $now, array $items, string $parameters, string $scope): void
    {
        $this->database->insert('checkouts', ['id' => $checkout->checkoutId, 'buyer_id' => $buyerId, 'state' => 'pending', 'authorized_at' => $now->format(DATE_ATOM), 'initial_deadline' => $checkout->initialDeadline->format(DATE_ATOM), 'expected_total_cents' => $checkout->totalCents, 'currency' => 'EUR']);
        foreach ($items as $item) {
            $this->database->insert('checkout_items', $item);
            $reserved = $this->database->executeStatement('INSERT INTO buyer_race_guards(buyer_id,race_id,reserved_checkout_id) VALUES (?,?,?) ON CONFLICT(buyer_id,race_id) DO UPDATE SET reserved_checkout_id=EXCLUDED.reserved_checkout_id,version=buyer_race_guards.version+1 WHERE buyer_race_guards.reserved_checkout_id IS NULL', [$buyerId, $item['race_id'], $checkout->checkoutId]);
            if (0 === $reserved) {
                throw new \DomainException('Race is already reserved by another checkout.');
            }
        }
        $this->database->insert('financial_operations', ['id' => $checkout->operationId, 'semantic_key' => 'checkout:'.$checkout->checkoutId.':payment', 'kind' => 'checkout_payment', 'checkout_id' => $checkout->checkoutId, 'expected_amount_cents' => $checkout->totalCents, 'currency' => 'EUR', 'provider' => 'stripe', 'provider_scope' => $scope, 'parameters' => $parameters, 'parameters_hash' => hash('sha256', $parameters), 'created_at' => $now->format(DATE_ATOM)]);
    }
}
