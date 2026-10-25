<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Application\Notifications\AvailabilityDigest;
use App\Infrastructure\Notifications\SymfonyAvailabilityDigestSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport;

final class AvailabilitySmtpTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('prices')]
    public function testRealSmtpContainsFrenchPriceTrustedOfferLinkAndOriginalDeadline(int $cents, string $expected): void
    {
        $key = bin2hex(random_bytes(12));
        $messageId = $key.'@runnerbib.local';
        $digest = new AvailabilityDigest('digest-'.$key.'@example.test', [['notification_id' => '00000000-0000-0000-0000-000000000010', 'race_id' => '00000000-0000-0000-0000-000000000004', 'listing_id' => '00000000-0000-0000-0000-000000000009', 'price_cents' => $cents, 'deadline' => new \DateTimeImmutable('2026-10-10T11:01:00Z')]]);
        (new SymfonyAvailabilityDigestSender(Transport::fromDsn('smtp://mailpit:1025'), 'http://localhost:8082', 'no-reply@example.test'))->send($digest, $messageId);
        $response = file_get_contents('http://mailpit:8025/api/v1/messages');
        self::assertIsString($response);
        $messages = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        self::assertIsArray($messages['messages']);
        $matched = [];
        foreach ($messages['messages'] as $message) {
            self::assertIsArray($message);
            if (($message['MessageID'] ?? null) === $messageId) {
                $matched[] = $message;
            }
        }
        self::assertCount(1, $matched);
        self::assertIsString($matched[0]['ID']);
        $response = file_get_contents('http://mailpit:8025/api/v1/message/'.$matched[0]['ID']);
        self::assertIsString($response);
        $mail = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($mail);
        self::assertIsString($mail['Text']);
        self::assertStringContainsString($expected, $mail['Text']);
        self::assertStringContainsString('http://localhost:8082/dossards/00000000-0000-0000-0000-000000000009', $mail['Text']);
        self::assertStringContainsString('2026-10-10T11:01:00+00:00', $mail['Text']);
    }

    /** @return iterable<string,array{int,string}> */
    public static function prices(): iterable
    {
        yield 'normal' => [10000, '100,00 EUR'];
        yield 'int_limit' => [9223372036854775807, '92233720368547758,07 EUR'];
    }
}
