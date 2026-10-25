<?php

declare(strict_types=1);

namespace App\Tests\Integration\Availability;

use App\Infrastructure\Availability\AvailabilityOfferHydrator;
use PHPUnit\Framework\TestCase;

final class AvailabilityOfferHydratorTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFields')]
    public function testMalformedProjectionCannotBecomeAnOffer(string $field, mixed $value, string $message): void
    {
        $row = ['id' => 'event', 'offer_version' => '1', 'price_cents' => '10000', 'currency' => 'EUR', 'available_at' => '2026-10-13T10:00:00Z', 'priority_ends_at' => '2026-10-13T11:00:00Z', 'recipient_count' => '2', 'invalidated_at' => null];
        $row[$field] = $value;
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);
        (new AvailabilityOfferHydrator())->view($row, 'listing', 'race');
    }

    /** @return iterable<string,array{string,mixed,string}> */
    public static function invalidFields(): iterable
    {
        yield 'numeric price' => ['price_cents', 10000, 'Invalid stored availability.'];
        yield 'missing currency' => ['currency', null, 'Invalid stored availability.'];
        yield 'numeric invalidation' => ['invalidated_at', 1, 'Invalid stored invalidation.'];
    }
}
