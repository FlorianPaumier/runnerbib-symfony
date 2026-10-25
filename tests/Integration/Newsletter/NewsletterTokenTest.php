<?php

declare(strict_types=1);

namespace App\Tests\Integration\Newsletter;

use App\Infrastructure\Newsletter\HmacNewsletterTokens;
use PHPUnit\Framework\TestCase;

final class NewsletterTokenTest extends TestCase
{
    public function testEmptySigningSecretFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HmacNewsletterTokens('');
    }

    public function testExplicitPreviousKeyPreservesMailedLinksAndNewLinksUseCurrentKey(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';
        $joinedAt = new \DateTimeImmutable('2026-10-10Z');
        $old = new HmacNewsletterTokens('old-newsletter-fixture');
        $current = new HmacNewsletterTokens('new-newsletter-fixture', ['old-newsletter-fixture']);
        self::assertSame($id, $current->verify($old->issue($id, $joinedAt))->subscriptionId);
        self::assertSame($id, (new HmacNewsletterTokens('new-newsletter-fixture'))->verify($current->issue($id, $joinedAt))->subscriptionId);
        $this->expectException(\InvalidArgumentException::class);
        $old->verify($current->issue($id, $joinedAt));
    }

    public function testRetiredKeyCannotAuthorizeWithdrawal(): void
    {
        $token = (new HmacNewsletterTokens('retired-fixture'))->issue('00000000-0000-0000-0000-000000000001', new \DateTimeImmutable('2026-10-10Z'));
        $this->expectException(\InvalidArgumentException::class);
        (new HmacNewsletterTokens('current-fixture'))->verify($token);
    }

    /** @param array<array-key,mixed> $keys */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPreviousSecrets')]
    public function testMalformedPreviousKeyConfigurationFailsClosed(array $keys): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HmacNewsletterTokens('current-fixture', $keys);
    }

    /** @return iterable<string,array{array<array-key,mixed>}> */
    public static function invalidPreviousSecrets(): iterable
    {
        yield 'keyed object' => [['previous' => 'old-fixture']];
        yield 'empty key' => [['']];
        yield 'integer key' => [[123]];
        yield 'null key' => [[null]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSignedPayloads')]
    public function testSignedMalformedPayloadCannotBecomeUnsubscribeAuthority(string $payload): void
    {
        $token = $payload.'.'.hash_hmac('sha256', "newsletter.unsubscribe\0".$payload, 'test-only-newsletter-secret');
        $this->expectException(\InvalidArgumentException::class);
        (new HmacNewsletterTokens('test-only-newsletter-secret'))->verify($token);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidSignedPayloads(): iterable
    {
        yield 'invalid base64' => ['a'];
        yield 'invalid URL alphabet' => ['%%%'];
        foreach (['missing separator' => 'missing', 'invalid UUID' => 'invalid|2026-10-10T10:00:00.000000+00:00', 'invalid date' => '00000000-0000-0000-0000-000000000001|invalid', 'date rollover' => '00000000-0000-0000-0000-000000000001|2026-13-32T10:00:00.000000+00:00'] as $name => $raw) {
            yield $name => [rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')];
        }
    }
}
