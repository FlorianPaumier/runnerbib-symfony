<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications;

use App\Application\Notifications\NotificationView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationViewBoundaryTest extends TestCase
{
    public function testTheDefaultPayloadVersionDisplaysAValidAvailability(): void
    {
        $view = $this->view('00000000-0000-0000-0000-000000000009', '2026-10-10T11:00:00Z');
        self::assertSame(1, $view->payloadVersion);
        $offer = $view->availability();
        self::assertNotNull($offer);
        self::assertSame('00000000-0000-0000-0000-000000000009', $offer['listingId']);
        self::assertSame(10500, $offer['priceCents']);
        self::assertSame('2026-10-10T11:00:00+00:00', $offer['priorityEndsAt']->format(DATE_ATOM));
    }

    #[DataProvider('invalidFacts')]
    public function testAvailabilityRejectsNonCanonicalIdentifiersAndInstants(string $listing, string $deadline): void
    {
        self::assertNull($this->view($listing, $deadline)->availability());
    }

    /** @return iterable<array{string,string}> */
    public static function invalidFacts(): iterable
    {
        yield ['x00000000-0000-0000-0000-000000000009', '2026-10-10T11:00:00Z'];
        yield ['00000000-0000-0000-0000-000000000009x', '2026-10-10T11:00:00Z'];
        yield ["00000000-0000-0000-0000-000000000009\n", '2026-10-10T11:00:00Z'];
        yield ['00000000-0000-0000-0000-000000000009', 'tomorrow 2026-10-10T11:00:00Z'];
        yield ['00000000-0000-0000-0000-000000000009', '2026-10-10T11:00:00Z tomorrow'];
        yield ['00000000-0000-0000-0000-000000000009', "2026-10-10T11:00:00Z\n"];
    }

    private function view(string $listing, string $deadline): NotificationView
    {
        return new NotificationView('message', 'availability', json_encode(['currency' => 'EUR', 'listing_id' => $listing, 'price_cents' => 10500, 'priority_ends_at' => $deadline], JSON_THROW_ON_ERROR), new \DateTimeImmutable('2026-10-10Z'), null, []);
    }
}
