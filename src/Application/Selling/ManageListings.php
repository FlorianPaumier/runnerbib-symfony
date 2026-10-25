<?php

declare(strict_types=1);

namespace App\Application\Selling;

use App\Application\Finance\SellerProvider;
use App\Application\Identity\CurrentIdentity;

final readonly class ManageListings
{
    public function __construct(private CurrentIdentity $identity, private ListingStore $store, private SellerProvider $provider)
    {
    }

    public function attachProof(string $listingId, string $proofId, int $expectedVersion): bool
    {
        $sellerId = $this->identity->accountId();

        return null !== $sellerId && $this->store->attachProof($sellerId, $listingId, $proofId, $expectedVersion);
    }

    public function draft(string $listingId, string $entitlementId, int $priceCents): bool
    {
        $sellerId = $this->identity->accountId();

        return null !== $sellerId && $this->store->draft($sellerId, $listingId, $entitlementId, $priceCents);
    }

    public function publish(string $listingId, int $expectedVersion, \DateTimeImmutable $now): bool
    {
        $sellerId = $this->identity->accountId();

        if (null !== $sellerId) {
            $this->provider->assertConfigured();
        }

        return null !== $sellerId && $this->store->publish($sellerId, $listingId, $expectedVersion, $now);
    }

    public function reprice(string $listingId, int $priceCents, int $expectedVersion, \DateTimeImmutable $now): bool
    {
        $sellerId = $this->identity->accountId();

        if (null !== $sellerId) {
            $this->provider->assertConfigured();
        }

        return null !== $sellerId && $this->store->reprice($sellerId, $listingId, $priceCents, $expectedVersion, $now);
    }

    public function withdraw(string $listingId, int $expectedVersion, \DateTimeImmutable $now): bool
    {
        $sellerId = $this->identity->accountId();

        return null !== $sellerId && $this->store->withdraw($sellerId, $listingId, $expectedVersion, $now);
    }
}
