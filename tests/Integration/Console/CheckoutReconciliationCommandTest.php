<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckoutReconciliationCommandTest extends IsolatedWebTestCase
{
    public function testEmptyBatchDoesNotRequireProviderCredentials(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:checkout:reconcile'));
        self::assertSame(0, $tester->execute(['--limit' => '1000']));
        self::assertSame("Reconciled checkouts: 0\n", $tester->getDisplay());
    }

    public function testDatabaseFailurePrintsOnlySanitizedRecoveryGuidance(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:checkout:reconcile'));
        $this->database->executeStatement('ALTER TABLE financial_operations RENAME TO private_unavailable_operations');
        foreach ([false, true] as $capture) {
            self::assertSame(1, $tester->execute(['--limit' => '1'], ['capture_stderr_separately' => $capture]));
            self::assertSame("Checkout reconciliation failed; payment protection retained for retry.\n", $capture ? $tester->getErrorOutput() : $tester->getDisplay());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLimits')]
    public function testInvalidLimitsCannotStartReconciliation(string|int $limit): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:checkout:reconcile'));
        foreach ([false, true] as $capture) {
            self::assertSame(2, $tester->execute(['--limit' => $limit], ['capture_stderr_separately' => $capture]));
            self::assertSame("Limit must be between 1 and 1000.\n", $capture ? $tester->getErrorOutput() : $tester->getDisplay());
        }
    }

    /** @return iterable<array{string|int}> */
    public static function invalidLimits(): iterable
    {
        yield ['0'];
        yield ['1001'];
        yield ['invalid'];
        yield [1];
    }
}
