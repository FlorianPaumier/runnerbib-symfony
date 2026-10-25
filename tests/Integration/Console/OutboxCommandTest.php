<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\Process\Process;

final class OutboxCommandTest extends IsolatedWebTestCase
{
    public function testConfiguredCommandUsesRealQueueAndControlsFailuresThroughConsoleInterface(): void
    {
        $kernel = self::bootKernel();
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:outbox:dispatch'));
        self::assertSame(0, $tester->execute(['--limit' => '1']));
        self::assertSame("Queued messages: 0\n", $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--limit' => '1001']));
        self::assertStringContainsString('between 1 and 1000', $tester->getDisplay());
        for ($index = 1; $index <= 2; ++$index) {
            $id = sprintf('00000000-0000-0000-0000-%012d', $index);
            $this->database->insert('outbox_messages', [
                'id' => $id, 'effect_key' => 'console:'.$index, 'aggregate_kind' => 'fixture',
                'aggregate_id' => $id, 'aggregate_version' => 1, 'event_type' => 'fixture.test',
                'payload_version' => 1, 'payload' => '{"private":"must-not-be-printed"}', 'created_at' => '2026-10-10Z',
            ]);
        }
        self::assertSame(0, $tester->execute(['--limit' => '1']));
        self::assertSame("Queued messages: 1\n", $tester->getDisplay());
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM messenger_messages'));
        $this->database->executeStatement('DROP TABLE messenger_messages');
        self::assertSame(1, $tester->execute(['--limit' => '1'], ['capture_stderr_separately' => true]));
        self::assertSame("Queued messages: 0, deferred: 1\n", $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
        self::assertStringNotContainsString('must-not-be-printed', $tester->getDisplay().$tester->getErrorOutput());
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM outbox_messages WHERE dispatched_at IS NULL'));
    }

    public function testEmptyOutboxTerminatesWithoutExposingPayloads(): void
    {
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);
        $process = new Process(['php', 'bin/console', 'app:outbox:dispatch', '--limit=1', '--env=test'], dirname(__DIR__, 3), ['DATABASE_URL' => $url]);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame("Queued messages: 0\n", $process->getOutput());
    }

    public function testBatchLimitQueuesOnlyTheRequestedNumber(): void
    {
        for ($index = 1; $index <= 3; ++$index) {
            $id = sprintf('00000000-0000-0000-0000-%012d', $index);
            $this->database->insert('outbox_messages', [
                'id' => $id, 'effect_key' => 'fixture:'.$index, 'aggregate_kind' => 'fixture',
                'aggregate_id' => $id, 'aggregate_version' => 1, 'event_type' => 'fixture.test',
                'payload_version' => 1, 'payload' => '{"private":"must-not-be-printed"}',
                'created_at' => '2026-10-08 00:00:00Z',
            ]);
        }
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);
        $process = new Process(['php', 'bin/console', 'app:outbox:dispatch', '--limit=2', '--env=test'], dirname(__DIR__, 3), ['DATABASE_URL' => $url]);
        $process->mustRun();
        self::assertSame("Queued messages: 2\n", $process->getOutput());
        self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM messenger_messages'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM outbox_messages WHERE dispatched_at IS NULL'));
    }

    public function testInvalidLimitIsRejected(): void
    {
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);
        $process = new Process(['php', 'bin/console', 'app:outbox:dispatch', '--limit=0', '--env=test'], dirname(__DIR__, 3), ['DATABASE_URL' => $url]);
        $process->run();
        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('between 1 and 1000', $process->getErrorOutput());
    }

    public function testQueueOutageRetainsIntentAndSanitizesFailure(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('outbox_messages', [
            'id' => $id, 'effect_key' => 'fixture:private', 'aggregate_kind' => 'fixture',
            'aggregate_id' => $id, 'aggregate_version' => 1, 'event_type' => 'fixture.test',
            'payload_version' => 1, 'payload' => '{"private":"must-not-be-printed"}',
            'created_at' => '2026-10-08 00:00:00Z',
        ]);
        $this->database->executeStatement('DROP TABLE messenger_messages');
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);
        $process = new Process(['php', 'bin/console', 'app:outbox:dispatch', '--limit=1', '--env=test'], dirname(__DIR__, 3), ['DATABASE_URL' => $url]);
        $process->run();
        self::assertSame(1, $process->getExitCode());
        self::assertSame("Queued messages: 0, deferred: 1\n", $process->getOutput());
        self::assertStringNotContainsString('must-not-be-printed', $process->getOutput().$process->getErrorOutput());
        self::assertNull($this->database->fetchOne('SELECT dispatched_at FROM outbox_messages'));
        self::assertNotNull($this->database->fetchOne('SELECT next_attempt_at FROM outbox_messages'));
        self::assertSame('1', $this->database->fetchOne("SELECT delivery_state->'dispatch'->>'attempts' FROM outbox_messages"));
    }

    public function testOutboxCommandFailsWhenTheOutboxTableIsUnavailable(): void
    {
        $this->database->executeStatement('DROP TABLE outbox_messages');
        $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(self::bootKernel()))->find('app:outbox:dispatch'));
        self::assertSame(1, $tester->execute(['--limit' => '1'], ['capture_stderr_separately' => true]));
        self::assertSame("Dispatch failed; intent retained for retry.\n", $tester->getErrorOutput());
    }

    public function testFailingIntentDoesNotBlockTheIntentsBehindIt(): void
    {
        $this->database->executeStatement('DROP TABLE messenger_messages');
        for ($index = 1; $index <= 3; ++$index) {
            $id = sprintf('00000000-0000-0000-0000-%012d', $index);
            $this->database->insert('outbox_messages', [
                'id' => $id, 'effect_key' => 'fixture:'.$index, 'aggregate_kind' => 'fixture',
                'aggregate_id' => $id, 'aggregate_version' => 1, 'event_type' => 'fixture.test',
                'payload_version' => 1, 'payload' => '{}',
                'created_at' => '2026-10-08 00:00:00Z',
            ]);
        }
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);
        $process = new Process(['php', 'bin/console', 'app:outbox:dispatch', '--limit=10', '--env=test'], dirname(__DIR__, 3), ['DATABASE_URL' => $url]);
        $process->run();
        self::assertSame(1, $process->getExitCode());
        self::assertSame("Queued messages: 0, deferred: 3\n", $process->getOutput());
        self::assertSame(3, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE delivery_state->'dispatch'->>'state'='pending'"));
    }
}
