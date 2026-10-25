<?php

declare(strict_types=1);

namespace App\Application\Checkout;

interface CartStore
{
    public function coordinateBuyer(string $buyerId): void;

    public function offer(string $listingId): CartOffer;

    /** @return list<CartOffer> */
    public function items(string $buyerId): array;

    public function remove(string $buyerId, string $listingId): void;

    public function add(string $buyerId, string $listingId, \DateTimeImmutable $now): void;
}
