<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications;

use App\Application\Notifications\NotificationView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationViewTest extends TestCase
{
    #[DataProvider('invalidPayloads')]
    public function testMalformedAvailabilityDoesNotBecomeAnOfferLink(string $payload): void
    {
        $notice = new NotificationView('notice', 'availability', $payload, new \DateTimeImmutable('2026-10-10Z'), null, []);
        self::assertNull($notice->availability());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'broken JSON' => ['{'];
        yield 'scalar JSON' => ['42'];
        yield 'missing fields' => ['{}'];
        $valid = ['listing_id' => '00000000-0000-0000-0000-000000000009', 'price_cents' => 10000, 'currency' => 'EUR', 'priority_ends_at' => '2026-10-10T11:00:00+00:00'];
        foreach (['listing_id' => '../private', 'price_cents' => 0, 'currency' => 'USD', 'priority_ends_at' => 'tomorrow'] as $field => $value) {
            yield $field => [json_encode(array_replace($valid, [$field => $value]), JSON_THROW_ON_ERROR)];
        }
        yield 'string price' => [json_encode(array_replace($valid, ['price_cents' => '10000']), JSON_THROW_ON_ERROR)];
        yield 'normalized impossible day' => [json_encode(array_replace($valid, ['priority_ends_at' => '2026-02-31T11:00:00+00:00']), JSON_THROW_ON_ERROR)];
        yield 'impossible date' => [json_encode(array_replace($valid, ['priority_ends_at' => '2026-99-99T11:00:00+00:00']), JSON_THROW_ON_ERROR)];
    }
}
