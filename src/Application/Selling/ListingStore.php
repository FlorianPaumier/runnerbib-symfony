<?php

declare(strict_types=1);

namespace App\Application\Selling;

interface ListingStore
{
    public function attachProof(string $sellerId, string $listingId, string $proofId, int $expectedVersion): bool;

    public function draft(string $sellerId, string $listingId, string $entitlementId, int $priceCents): bool;

    public function publish(string $sellerId, string $listingId, int $expectedVersion, \DateTimeImmutable $now): bool;

    public function reprice(string $sellerId, string $listingId, int $priceCents, int $expectedVersion, \DateTimeImmutable $now): bool;

    public function withdraw(string $sellerId, string $listingId, int $expectedVersion, \DateTimeImmutable $now): bool;
}
