<?php

declare(strict_types=1);

namespace App\Application\Availability;

interface AvailabilityAccessStore
{
    public function forListing(string $listingId, ?string $accountId): ?AvailabilityAccessFacts;

    /**
     * Access facts of several listings, read in a single query.
     *
     * @param list<string> $listingIds
     *
     * @return array<string,AvailabilityAccessFacts>
     */
    public function forListings(array $listingIds, ?string $accountId): array;

    public function forEvent(string $eventId, ?string $accountId): ?AvailabilityAccessFacts;

    /**
     * Access facts of one offer for several recipients, read in a single query.
     *
     * @param list<string> $accountIds
     *
     * @return array<string,AvailabilityAccessFacts>
     */
    public function forEventRecipients(string $eventId, array $accountIds): array;
}
