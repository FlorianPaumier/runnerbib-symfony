<?php

declare(strict_types=1);

namespace App\Infrastructure\Availability;

use App\Application\Availability\AvailabilityOfferView;

final readonly class AvailabilityOfferHydrator
{
    /** @param array<string,mixed> $row */
    public function view(array $row, string $listingId, string $raceId): AvailabilityOfferView
    {
        foreach (['id', 'offer_version', 'price_cents', 'currency', 'available_at', 'priority_ends_at', 'recipient_count'] as $key) {
            if (!is_string($row[$key])) {
                throw new \UnexpectedValueException('Invalid stored availability.');
            }
        }
        if (!is_string($row['invalidated_at']) && null !== $row['invalidated_at']) {
            throw new \UnexpectedValueException('Invalid stored invalidation.');
        }

        return new AvailabilityOfferView($row['id'], $listingId, $raceId, (int) $row['offer_version'], (int) $row['price_cents'], $row['currency'], new \DateTimeImmutable($row['available_at']), new \DateTimeImmutable($row['priority_ends_at']), (int) $row['recipient_count'], null === $row['invalidated_at'] ? null : new \DateTimeImmutable($row['invalidated_at']));
    }
}
