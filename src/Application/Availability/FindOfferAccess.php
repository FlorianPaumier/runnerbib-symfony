<?php

declare(strict_types=1);

namespace App\Application\Availability;

use App\Domain\Availability\OfferAccessPolicy;

final readonly class FindOfferAccess
{
    public function __construct(private AvailabilityAccessStore $store)
    {
    }

    public function forListing(string $listingId, ?string $accountId, \DateTimeImmutable $now): ?OfferAccessView
    {
        return $this->view($this->store->forListing($listingId, $accountId), $now);
    }

    public function forEvent(string $eventId, ?string $accountId, \DateTimeImmutable $now): ?OfferAccessView
    {
        return $this->view($this->store->forEvent($eventId, $accountId), $now);
    }

    /**
     * Access of several listings, read in one query.
     *
     * @param list<string> $listingIds
     *
     * @return array<string,OfferAccessView>
     */
    public function forListings(array $listingIds, ?string $accountId, \DateTimeImmutable $now): array
    {
        $views = [];
        foreach ($this->store->forListings($listingIds, $accountId) as $listingId => $facts) {
            $view = $this->view($facts, $now);
            if (null !== $view) {
                $views[$listingId] = $view;
            }
        }

        return $views;
    }

    /**
     * Access of one offer for several recipients, read in one query.
     *
     * @param list<string> $accountIds
     *
     * @return array<string,OfferAccessView>
     */
    public function forEventRecipients(string $eventId, array $accountIds, \DateTimeImmutable $now): array
    {
        $views = [];
        foreach ($this->store->forEventRecipients($eventId, $accountIds) as $accountId => $facts) {
            $view = $this->view($facts, $now);
            if (null !== $view) {
                $views[$accountId] = $view;
            }
        }

        return $views;
    }

    private function view(?AvailabilityAccessFacts $facts, \DateTimeImmutable $now): ?OfferAccessView
    {
        if (null === $facts) {
            return null;
        }
        $offer = $facts->offer;
        $policy = new OfferAccessPolicy();
        $state = $policy->state($facts->current, $offer->recipientCount, $offer->availableAt, $offer->priorityEndsAt, $now, $facts->resaleDeadline);

        return new OfferAccessView($offer->eventId, $offer->listingId, $offer->raceId, $offer->offerVersion, $offer->priceCents, $offer->currency, $offer->availableAt, $offer->priorityEndsAt, $offer->recipientCount, $state->value, $facts->snapshotMember, $policy->permits($state, $facts->snapshotMember, $facts->accountEligible, $facts->checkoutProtected), $facts->resaleDeadline, $facts->checkoutProtected);
    }
}
