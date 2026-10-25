<?php

declare(strict_types=1);

namespace App\Application\Checkout;

final readonly class CartOffer
{
    public function __construct(public string $listingId, public string $raceId, public string $sellerId, public int $priceCents, public string $participation, public ?\stdClass $registrationSchema)
    {
    }
}
