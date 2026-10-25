<?php

declare(strict_types=1);

namespace App\Domain\Selling;

final readonly class PublicationEligibility
{
    public function __construct(private bool $activeAccount, private bool $verifiedAccount, private bool $currentOwner, private bool $approvedCurrentProof, private bool $sellerReady, private ?\DateTimeImmutable $resaleDeadline, private ?int $minimumPrice, private ?int $maximumPrice, private bool $publishedRace)
    {
    }

    public function allows(int $priceCents, \DateTimeImmutable $now): bool
    {
        return $this->publishedRace && $this->activeAccount && $this->verifiedAccount && $this->currentOwner && $this->approvedCurrentProof && $this->sellerReady
            && (null !== $this->resaleDeadline && $now < $this->resaleDeadline)
            && (null === $this->minimumPrice || $priceCents >= $this->minimumPrice)
            && (null === $this->maximumPrice || $priceCents <= $this->maximumPrice);
    }
}
