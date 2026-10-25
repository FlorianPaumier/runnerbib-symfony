<?php

declare(strict_types=1);

namespace App\Application\Selling;

use App\Application\Availability\OfferAccessView;
use App\Application\Races\PublicRace;

final readonly class ListingOfferView
{
    public function __construct(public PublicRace $race, public OfferAccessView $access, public int $buyerFeeCents, public int $buyerTotalCents)
    {
    }
}
