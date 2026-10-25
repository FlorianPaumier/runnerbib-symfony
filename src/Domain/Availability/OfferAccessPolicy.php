<?php

declare(strict_types=1);

namespace App\Domain\Availability;

final readonly class OfferAccessPolicy
{
    public function state(bool $current, int $recipientCount, \DateTimeImmutable $availableAt, \DateTimeImmutable $priorityEndsAt, \DateTimeImmutable $now, ?\DateTimeImmutable $resaleDeadline): OfferAccessState
    {
        if (!$current || $now < $availableAt || null === $resaleDeadline || $now >= $resaleDeadline) {
            return OfferAccessState::Unavailable;
        }

        return $recipientCount > 0 && $now < $priorityEndsAt ? OfferAccessState::Priority : OfferAccessState::Public;
    }

    public function permits(OfferAccessState $state, bool $snapshotMember, bool $accountEligible, bool $checkoutProtected): bool
    {
        return !$checkoutProtected && $accountEligible && (OfferAccessState::Public === $state || (OfferAccessState::Priority === $state && $snapshotMember));
    }
}
