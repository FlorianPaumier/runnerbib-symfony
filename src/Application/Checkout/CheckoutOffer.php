<?php

declare(strict_types=1);

namespace App\Application\Checkout;

final readonly class CheckoutOffer
{
    public function __construct(public string $listingId, public string $raceId, public string $entitlementId, public string $sellerId, public string $participation, public ?int $registrationSchemaVersion, public ?\stdClass $registrationSchema)
    {
    }
}
