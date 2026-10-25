<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AvailabilityCommandTest extends IsolatedWebTestCase
{
    public function testConfiguredCommandStopsAtItsBoundAndLeavesFutureBatchesPending(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:availability:run'));
        self::assertSame(0, $tester->execute(['--limit' => '1']));
        self::assertSame("Processed batch chunks: 0\n", $tester->getDisplay());
        foreach ([1, 2, 3] as $index) {
            $this->database->insert('scheduled_runs', ['id' => sprintf('00000000-0000-0000-0000-%012d', $index), 'job_name' => 'availability_notifications', 'local_run_date' => '2026-01-01', 'timezone' => 'UTC', 'scheduled_at' => '2026-01-01 00:'.sprintf('%02d', $index * 5).':00Z', 'state' => 'pending']);
        }
        $this->database->insert('scheduled_runs', ['id' => '00000000-0000-0000-0000-000000000004', 'job_name' => 'availability_notifications', 'local_run_date' => '2099-01-01', 'timezone' => 'UTC', 'scheduled_at' => '2099-01-01Z', 'state' => 'pending']);
        self::assertSame(0, $tester->execute(['--limit' => '2']));
        self::assertSame("Processed batch chunks: 2\n", $tester->getDisplay());
        self::assertEquals(2, $this->database->fetchOne("SELECT count(*) FROM scheduled_runs WHERE state='completed'"));
        self::assertEquals(2, $this->database->fetchOne("SELECT count(*) FROM scheduled_runs WHERE state='pending'"));
    }

    public function testOutageRollsBackBatchAndPrintsOnlyASanitizedFailure(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:availability:run'));
        $this->database->insert('scheduled_runs', ['id' => '00000000-0000-0000-0000-000000000001', 'job_name' => 'availability_notifications', 'local_run_date' => '2026-01-01', 'timezone' => 'UTC', 'scheduled_at' => '2026-01-01Z', 'state' => 'pending']);
        $this->database->executeStatement('DROP TABLE notifications');
        self::assertSame(1, $tester->execute(['--limit' => '1'], ['capture_stderr_separately' => true]));
        self::assertSame("Processed batch chunks: 0, failed: 1\n", $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
        self::assertSame(1, $tester->execute(['--limit' => '1']));
        self::assertSame("Processed batch chunks: 0, failed: 1\n", $tester->getDisplay());
        self::assertSame('pending', $this->database->fetchOne('SELECT state FROM scheduled_runs'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLimits')]
    public function testInvalidLimitsAreRejectedWithoutRunningBatches(string|int $limit): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:availability:run'));
        self::assertSame(2, $tester->execute(['--limit' => $limit]));
        self::assertSame("Limit must be between 1 and 1000.\n", $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--limit' => $limit], ['capture_stderr_separately' => true]));
        self::assertSame("Limit must be between 1 and 1000.\n", $tester->getErrorOutput());
    }

    /** @return iterable<string,array{string|int}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'over_bound' => ['1001'];
        yield 'invalid' => ['abc'];
        yield 'nonstring' => [1];
    }
}
