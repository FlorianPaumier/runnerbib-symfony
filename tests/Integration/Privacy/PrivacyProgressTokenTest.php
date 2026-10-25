<?php

declare(strict_types=1);

namespace App\Tests\Integration\Privacy;

use PHPUnit\Framework\TestCase;

final class PrivacyProgressTokenTest extends TestCase
{
    public function testStatusAuthorityIsPurposeScopedOwnerVersionBoundAndExpiresExactly(): void
    {
        $tokens = new \App\Infrastructure\Privacy\HmacPrivacyRequestTokens('test-only-privacy-secret');
        $expiresAt = new \DateTimeImmutable('2026-10-12T12:00:00Z');
        $token = $tokens->issue('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', 3, $expiresAt);
        $authority = $tokens->verify($token, $expiresAt->modify('-1 microsecond'));
        self::assertSame('00000000-0000-0000-0000-000000000001', $authority->requestId);
        self::assertSame('00000000-0000-0000-0000-000000000002', $authority->accountId);
        self::assertSame(3, $authority->accountVersion);
        self::assertEquals($expiresAt, $authority->expiresAt);
        try {
            $tokens->verify($token, $expiresAt);
            self::fail('Status authority must expire at the exact configured boundary.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Privacy status authority is invalid.', $error->getMessage());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedAuthorities')]
    public function testSignedMalformedStatusAuthorityCannotBecomeAccess(string $payload): void
    {
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $token = $encoded.'.'.hash_hmac('sha256', "privacy.request_status\0".$encoded, 'test-only-privacy-secret');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Privacy status authority is invalid.');
        (new \App\Infrastructure\Privacy\HmacPrivacyRequestTokens('test-only-privacy-secret'))->verify($token, new \DateTimeImmutable('2026-10-12Z'));
    }

    /** @return iterable<string,array{string}> */
    public static function malformedAuthorities(): iterable
    {
        $request = '00000000-0000-0000-0000-000000000001';
        $owner = '00000000-0000-0000-0000-000000000002';
        $expiry = '2030-10-12T12:00:00.000000+00:00';
        yield 'request' => ['invalid|'.$owner.'|3|'.$expiry];
        yield 'owner' => [$request.'|invalid|3|'.$expiry];
        foreach (['0', '-1', 'bad', '9223372036854775808', '03'] as $version) {
            yield 'version '.$version => [$request.'|'.$owner.'|'.$version.'|'.$expiry];
        }
        yield 'missing field' => [$request.'|'.$owner.'|3'];
        yield 'date' => [$request.'|'.$owner.'|3|invalid'];
        yield 'rollover' => [$request.'|'.$owner.'|3|2030-02-30T12:00:00.000000+00:00'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSignatures')]
    public function testTamperedWrongPurposeOrWrongSecretCannotGrantStatus(string $scenario): void
    {
        $tokens = new \App\Infrastructure\Privacy\HmacPrivacyRequestTokens('test-only-privacy-secret');
        $token = $tokens->issue('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', 3, new \DateTimeImmutable('2030-10-12Z'));
        [$payload, $signature] = explode('.', $token);
        $invalid = match ($scenario) {
            'purpose' => $payload.'.'.hash_hmac('sha256', "newsletter.unsubscribe\0".$payload, 'test-only-privacy-secret'),
            'secret' => $payload.'.'.hash_hmac('sha256', "privacy.request_status\0".$payload, 'other-secret'),
            'signature' => $payload.'.'.str_repeat('0', 64),
            'structure' => $token.'.extra',
            'alphabet' => '!'.$payload.'.'.$signature,
            'base64' => 'A.'.hash_hmac('sha256', "privacy.request_status\0A", 'test-only-privacy-secret'),
            default => throw new \LogicException('Invalid scenario.'),
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Privacy status authority is invalid.');
        $tokens->verify($invalid, new \DateTimeImmutable('2026-10-12Z'));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidSignatures(): iterable
    {
        foreach (['purpose', 'secret', 'signature', 'structure', 'alphabet', 'base64'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    public function testStatusSignerRequiresAnExplicitSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Privacy signing secret is required.');
        new \App\Infrastructure\Privacy\HmacPrivacyRequestTokens('');
    }
}
