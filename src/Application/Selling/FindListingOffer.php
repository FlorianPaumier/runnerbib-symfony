<?php

declare(strict_types=1);

namespace App\Application\Selling;

use App\Application\Availability\FindOfferAccess;
use App\Application\Races\FindPublicRace;
use App\Domain\Finance\FeePolicy;

final readonly class FindListingOffer
{
    public function __construct(private FindOfferAccess $access, private FindPublicRace $races, private FeePolicy $fees)
    {
    }

    public function get(string $listingId, ?string $accountId, \DateTimeImmutable $now): ?ListingOfferView
    {
        $access = $this->access->forListing($listingId, $accountId, $now);
        if (null === $access) {
            return null;
        }
        $race = $this->races->get($access->raceId);
        if (null === $race) {
            return null;
        }
        try {
            $fees = $this->fees->quote($access->priceCents);
        } catch (\InvalidArgumentException|\OverflowException) {
            return null;
        }

        return new ListingOfferView($race, $access, $fees->buyerFeeCents, $fees->buyerTotalCents);
    }
}
