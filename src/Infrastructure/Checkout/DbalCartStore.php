<?php

declare(strict_types=1);

namespace App\Infrastructure\Checkout;

use App\Application\Checkout\CartOffer;
use App\Application\Checkout\CartStore;
use Doctrine\DBAL\Connection;

final readonly class DbalCartStore implements CartStore
{
    public function __construct(private Connection $database)
    {
    }

    public function coordinateBuyer(string $buyerId): void
    {
        if (false === $this->database->fetchOne("SELECT id FROM accounts WHERE id = ? AND lifecycle_state = 'active' AND verified_at IS NOT NULL FOR UPDATE", [$buyerId])) {
            throw new \DomainException('An active verified buyer is required.');
        }
    }

    public function offer(string $listingId): CartOffer
    {
        $row = $this->database->fetchAssociative('SELECT l.id::text,l.race_id::text,l.seller_id::text,l.price_cents,r.participation,r.registration_schema FROM resale_listings l JOIN races r ON r.id=l.race_id WHERE l.id = ?', [$listingId]);
        if (false === $row) {
            throw new \DomainException('Listing is unavailable.');
        }

        return $this->hydrate($row);
    }

    public function items(string $buyerId): array
    {
        return array_map($this->hydrate(...), $this->database->fetchAllAssociative('SELECT l.id::text,l.race_id::text,l.seller_id::text,l.price_cents,r.participation,r.registration_schema FROM cart_items c JOIN resale_listings l ON l.id=c.listing_id JOIN races r ON r.id=l.race_id WHERE c.buyer_id = ? ORDER BY c.added_at,l.id', [$buyerId]));
    }

    public function remove(string $buyerId, string $listingId): void
    {
        $this->database->delete('cart_items', ['buyer_id' => $buyerId, 'listing_id' => $listingId]);
    }

    public function add(string $buyerId, string $listingId, \DateTimeImmutable $now): void
    {
        $this->database->insert('cart_items', ['buyer_id' => $buyerId, 'listing_id' => $listingId, 'added_at' => $now->format(DATE_ATOM)]);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): CartOffer
    {
        if (!is_string($row['id']) || !is_string($row['race_id']) || !is_string($row['seller_id']) || !is_int($row['price_cents']) || !is_string($row['participation']) || (null !== $row['registration_schema'] && !is_string($row['registration_schema']))) {
            throw new \UnexpectedValueException('Invalid stored cart offer.');
        }
        $schema = null;
        if (null !== $row['registration_schema']) {
            $decoded = json_decode($row['registration_schema'], false, 512, JSON_THROW_ON_ERROR);
            \assert($decoded instanceof \stdClass);
            $schema = $decoded;
        }

        return new CartOffer($row['id'], $row['race_id'], $row['seller_id'], $row['price_cents'], $row['participation'], $schema);
    }
}
