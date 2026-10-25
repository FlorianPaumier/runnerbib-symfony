<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Domain\Identity\Actor;
use PHPUnit\Framework\TestCase;

final class AccessPolicyTest extends TestCase
{
    public function testResourceAndFinancialPermissionsAreIndependent(): void
    {
        $runner = new Actor('runner-id', ['runner']);
        self::assertTrue($runner->canAccessAccount('runner-id'));
        self::assertFalse($runner->canAccessAccount('other-id'));
        self::assertFalse($runner->canExecuteFinancialAction());
        self::assertTrue((new Actor('admin-id', ['admin']))->canAccessAccount('other-id'));
        self::assertFalse((new Actor('admin-id', ['admin']))->canExecuteFinancialAction());
        self::assertTrue((new Actor('super-id', ['super_admin']))->canExecuteFinancialAction());
        self::assertTrue((new Actor('super-id', ['super_admin']))->canAccessAccount('other-id'));
    }
}
