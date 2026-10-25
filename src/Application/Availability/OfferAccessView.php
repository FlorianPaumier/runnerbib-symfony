<?php

declare(strict_types=1);

namespace App\Application\Availability;

final readonly class OfferAccessView
{
    public function __construct(public string $eventId, public string $listingId, public string $raceId, public int $offerVersion, public int $priceCents, public string $currency, public \DateTimeImmutable $availableAt, public \DateTimeImmutable $priorityEndsAt, public int $recipientCount, public string $accessState, public bool $isSnapshotMember, public bool $canInitiatePurchase, public ?\DateTimeImmutable $resaleDeadline = null, public bool $hasCheckoutProtection = false)
    {
    }
}
