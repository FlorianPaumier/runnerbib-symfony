<?php

declare(strict_types=1);

namespace App\Application\Selling;

use App\Domain\Finance\FeePolicy;
use App\Domain\Finance\FeeQuote;

final readonly class SellerInventoryItem
{
    /** @param list<array{id: string, submittedAt: string}> $approvedProofs */
    public function __construct(
        public string $entitlementId,
        public string $raceId,
        public string $raceName,
        public string $bibNumber,
        public bool $ownsEntitlement,
        public ?string $listingId,
        public ?int $listingVersion,
        public ?int $priceCents,
        public ?string $listingState,
        public ?string $proofId,
        public ?string $proofDecision,
        public ?int $proofVersion,
        public ?int $reviewedVersion,
        public bool $checkoutProtected,
        public array $approvedProofs,
    ) {
    }

    public function fees(): ?FeeQuote
    {
        if (null === $this->priceCents) {
            return null;
        }
        try {
            return (new FeePolicy())->quote($this->priceCents);
        } catch (\InvalidArgumentException|\OverflowException) {
            return null;
        }
    }
}
