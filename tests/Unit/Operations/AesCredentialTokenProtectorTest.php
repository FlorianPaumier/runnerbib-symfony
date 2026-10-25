<?php

declare(strict_types=1);

namespace App\Tests\Unit\Operations;

use App\Infrastructure\Operations\AesCredentialTokenProtector;
use PHPUnit\Framework\TestCase;

final class AesCredentialTokenProtectorTest extends TestCase
{
    public function testTokenIsProtectedAndRevealed(): void
    {
        $protector = new AesCredentialTokenProtector('credential-token-secret-for-tests-0123456789');
        $protected = $protector->protect('a'.str_repeat('b', 63));

        self::assertNotSame('a'.str_repeat('b', 63), $protected);
        self::assertSame('a'.str_repeat('b', 63), $protector->reveal($protected));
    }

    public function testShortSecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AesCredentialTokenProtector('short');
    }

    public function testUnreadableProtectedTokenIsRefused(): void
    {
        $protector = new AesCredentialTokenProtector('credential-token-secret-for-tests-0123456789');
        $this->expectException(\RuntimeException::class);
        $protector->reveal('not-base64');
    }

    public function testTamperedProtectedTokenIsRefused(): void
    {
        $protector = new AesCredentialTokenProtector('credential-token-secret-for-tests-0123456789');
        $protected = $protector->protect('a'.str_repeat('b', 63));
        $tampered = substr($protected, 0, -4).'AAAA';
        $this->expectException(\RuntimeException::class);
        $protector->reveal($tampered);
    }
}
