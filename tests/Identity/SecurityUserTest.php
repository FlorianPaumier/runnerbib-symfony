<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Infrastructure\Identity\SecurityUser;
use PHPUnit\Framework\TestCase;

final class SecurityUserTest extends TestCase
{
    public function testSessionEqualityIncludesAccountVersionAndPassword(): void
    {
        $user = new SecurityUser('id', 'runner@example.test', 'hash', ['runner'], 1);
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertSame('hash', $user->getPassword());
        self::assertSame('runner@example.test', $user->getUserIdentifier());
        self::assertSame('id', $user->accountId);
        self::assertTrue($user->isEqualTo(new SecurityUser('id', 'runner@example.test', 'hash', ['runner'], 1)));
        self::assertFalse($user->isEqualTo(new SecurityUser('id', 'runner@example.test', 'new', ['runner'], 2)));
        self::assertFalse($user->isEqualTo(new SecurityUser('id', 'runner@example.test', 'hash', ['runner'], 2)));
        self::assertFalse($user->isEqualTo(new SecurityUser('id', 'runner@example.test', 'hash', ['admin'], 1)));
        self::assertFalse($user->isEqualTo(new SecurityUser('id', 'runner@example.test', 'new', ['runner'], 1)));
        self::assertFalse($user->isEqualTo(new SecurityUser('other', 'runner@example.test', 'hash', ['runner'], 1)));
        self::assertSame(['ROLE_ADMIN'], (new SecurityUser('admin', 'admin@example.test', null, ['admin'], 1))->getRoles());
        self::assertSame(['ROLE_SUPER_ADMIN'], (new SecurityUser('super', 'super@example.test', null, ['super_admin'], 1))->getRoles());
    }

    public function testMissingStoredEmailFailsClosed(): void
    {
        $this->expectException(\LogicException::class);
        (new SecurityUser('id', '', null, ['runner'], 1))->getUserIdentifier();
    }
}
