<?php

declare(strict_types=1);

namespace App\Application\Availability;

final readonly class AvailabilityAccessFacts
{
    public function __construct(public AvailabilityOfferView $offer, public bool $current, public bool $snapshotMember, public bool $accountEligible, public ?\DateTimeImmutable $resaleDeadline, public bool $checkoutProtected)
    {
    }
}
