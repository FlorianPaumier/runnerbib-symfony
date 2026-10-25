<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Domain\Identity\AccountLifecycle;
use App\Domain\Identity\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountLifecycleTest extends TestCase
{
    public function testOnlyAdministrativeRolesCanManageLifecycle(): void
    {
        self::assertFalse((new Actor('runner', ['runner']))->canAdministerAccounts());
        self::assertTrue((new Actor('admin', ['admin']))->canAdministerAccounts());
        self::assertTrue((new Actor('super', ['super_admin']))->canAdministerAccounts());
        self::assertFalse((new Actor('unknown', ['unknown']))->canAdministerAccounts());
    }

    public function testSuspendAndReactivateRequireReasonAndNeverRestoreAnonymizedIdentity(): void
    {
        $suspend = new AccountLifecycle('suspended', '  Safety investigation  ');
        self::assertSame('Safety investigation', $suspend->reason);
        self::assertSame('suspended', $suspend->transitionFrom('active'));
        self::assertNull($suspend->transitionFrom('suspended'));
        self::assertNull($suspend->transitionFrom('anonymized'));
        self::assertNull($suspend->transitionFrom('invalid'));
        self::assertSame('active', (new AccountLifecycle('active', 'Reviewed'))->transitionFrom('suspended'));
    }

    /** @return iterable<array{string,string}> */
    public static function invalid(): iterable
    {
        yield ['anonymized', 'Reason'];
        yield ['active', '  '];
    }

    #[DataProvider('invalid')]
    public function testInvalidChangeIsRejected(string $state, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccountLifecycle($state, $reason);
    }
}
