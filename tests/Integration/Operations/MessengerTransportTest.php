<?php

declare(strict_types=1);

namespace App\Tests\Integration\Operations;

use App\Application\Operations\OutboxMessage;
use App\Tests\Support\IsolatedDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Process\Process;

final class MessengerTransportTest extends KernelTestCase
{
    private Connection $admin;
    private Connection $database;
    private string $databaseName;
    private string $databaseUrl;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = self::getContainer()->get(Connection::class);
        $this->databaseName = 'queue_test_'.bin2hex(random_bytes(6));
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($url);
        IsolatedDatabase::create($this->admin, $this->databaseName, dirname(__DIR__, 3), $url);
        $parameters = $this->admin->getParams();
        $parameters['dbname'] = $this->databaseName;
        $this->database = DriverManager::getConnection($parameters);
        $isolatedUrl = preg_replace('~/[^/?]+(?=\\?|$)~', '/'.$this->databaseName, $url);
        self::assertIsString($isolatedUrl);
        $this->databaseUrl = $isolatedUrl;
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->admin->executeStatement('DROP DATABASE '.$this->databaseName.' WITH (FORCE)');
        parent::tearDown();
    }

    public function testQueueMigrationCanBeAppliedRolledBackAndReplayedIndependently(): void
    {
        $this->migrate('latest');
        self::assertSame('messenger_messages', $this->database->fetchOne("SELECT to_regclass('public.messenger_messages')::text"));
        $this->migrate('DoctrineMigrations\\Version20261008000100');
        self::assertNull($this->database->fetchOne("SELECT to_regclass('public.messenger_messages')::text"));
        self::assertSame('races', $this->database->fetchOne("SELECT to_regclass('public.races')::text"));
        $this->migrate('latest');
        self::assertSame('messenger_messages', $this->database->fetchOne("SELECT to_regclass('public.messenger_messages')::text"));
    }

    public function testMessagesPersistAcrossTransportRecreationAndFailedQueueIsSeparate(): void
    {
        $this->migrate('latest');
        $message = new OutboxMessage('00000000-0000-0000-0000-000000000001', 'test-effect', 'account', '00000000-0000-0000-0000-000000000002', 1, 'test.event', 1, '{}', new \DateTimeImmutable('2026-10-08Z'));
        $async = $this->transport('async');
        $async->send(new Envelope($message));
        $failed = $this->transport('failed');
        self::assertSame(0, $failed->getMessageCount());
        $async = $this->transport('async');
        self::assertSame(1, $async->getMessageCount());
        $received = iterator_to_array($async->get());
        self::assertCount(1, $received);
        self::assertEquals($message, $received[0]->getMessage());
        $failed->send($received[0]);
        $async->ack($received[0]);
        self::assertSame(0, $async->getMessageCount());
        self::assertSame(1, $failed->getMessageCount());
    }

    public function testWorkerExhaustsRetriesIntoDurableFailedQueue(): void
    {
        $this->migrate('latest');
        $this->transport('async')->send(new Envelope(new \stdClass()));
        $worker = new Process(['php', 'bin/console', 'messenger:consume', 'async', '--failure-limit=4', '--time-limit=15', '--sleep=0.01', '--no-interaction', '-vv'], self::getContainer()->getParameter('kernel.project_dir'), ['DATABASE_URL' => $this->databaseUrl, 'APP_ENV' => 'test']);
        $worker->setTimeout(20);
        $worker->mustRun();
        self::assertSame(0, $this->transport('async')->getMessageCount(), $worker->getOutput().$worker->getErrorOutput().json_encode($this->database->fetchAllAssociative('SELECT queue_name,created_at,available_at,delivered_at FROM messenger_messages'), JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->transport('failed')->getMessageCount(), $worker->getOutput().$worker->getErrorOutput());
        $failed = iterator_to_array($this->transport('failed')->get());
        self::assertCount(1, $failed);
        self::assertEquals(new \stdClass(), $failed[0]->getMessage());
    }

    public function testIdleWorkerStopsGracefullyAtItsTimeLimit(): void
    {
        $this->migrate('latest');
        $worker = new Process(['php', 'bin/console', 'messenger:consume', 'async', '--time-limit=1', '--sleep=0.01', '--no-interaction'], self::getContainer()->getParameter('kernel.project_dir'), ['DATABASE_URL' => $this->databaseUrl, 'APP_ENV' => 'test']);
        $worker->setTimeout(5);
        $worker->mustRun();
        self::assertSame(0, $worker->getExitCode());
        self::assertSame(0, $this->transport('async')->getMessageCount());
    }

    private function transport(string $queue): DoctrineTransport
    {
        return new DoctrineTransport(new TransportConnection(['queue_name' => $queue, 'auto_setup' => false], $this->database), new PhpSerializer());
    }

    private function migrate(string $version): void
    {
        $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', $version, '--no-interaction'], self::getContainer()->getParameter('kernel.project_dir'), ['DATABASE_URL' => $this->databaseUrl]);
        $process->mustRun();
    }
}
