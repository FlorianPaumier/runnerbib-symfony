<?php

declare(strict_types=1);

namespace App\Application\Selling;

final readonly class DiscoverListingOffers
{
    public function __construct(private PublicListingCatalog $catalog, private FindListingOffer $offers)
    {
    }

    /** @return list<ListingOfferView> */
    public function recent(\DateTimeImmutable $now): array
    {
        $offers = [];
        foreach ($this->catalog->recentListingIds() as $id) {
            $offer = $this->offers->get($id, null, $now);
            if (null !== $offer && 'unavailable' !== $offer->access->accessState) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }
}
