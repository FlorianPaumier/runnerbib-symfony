<?php

declare(strict_types=1);

namespace App\Application\Availability;

final readonly class CapturedAvailability
{
    public function __construct(public AvailabilityOfferView $offer, public bool $created)
    {
    }
}
