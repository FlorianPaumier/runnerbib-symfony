<?php

declare(strict_types=1);

namespace App\Tests\Integration\Operations;

use App\Application\Operations\AuditEntry;
use App\Application\Operations\DispatchOutbox;
use App\Application\Operations\MessageDispatcher;
use App\Application\Operations\OutboxDispatchOutcome;
use App\Application\Operations\OutboxMessage;
use App\Infrastructure\Operations\DbalAuditWriter;
use App\Infrastructure\Operations\DbalEffectConsumer;
use App\Infrastructure\Operations\DbalOutboxQueue;
use App\Infrastructure\Operations\DbalOutboxWriter;
use App\Infrastructure\Operations\DbalTransactionManager;
use App\Infrastructure\Operations\MessengerMessageDispatcher;
use App\Tests\Support\IsolatedDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Process\Process;

final class DurableOperationsTest extends KernelTestCase
{
    private Connection $admin;
    private Connection $database;
    private string $databaseName;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = self::getContainer()->get(Connection::class);
        $this->databaseName = 'operations_test_'.bin2hex(random_bytes(6));
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($url);
        IsolatedDatabase::create($this->admin, $this->databaseName, dirname(__DIR__, 3), $url);
        $parameters = $this->admin->getParams();
        $parameters['dbname'] = $this->databaseName;
        $this->database = DriverManager::getConnection($parameters);
        $isolatedUrl = preg_replace('~/[^/?]+(?=\\?|$)~', '/'.$this->databaseName, $url);
        self::assertIsString($isolatedUrl);
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->admin->executeStatement('DROP DATABASE '.$this->databaseName.' WITH (FORCE)');
        parent::tearDown();
    }

    public function testBusinessAuditAndOutboxCommitTogetherAndRollbackTogether(): void
    {
        $transaction = new DbalTransactionManager($this->database);
        $write = function (): void {
            $this->database->executeStatement("INSERT INTO accounts(id,terms_version,terms_accepted_at) VALUES ('00000000-0000-0000-0000-000000000001','v1','2026-10-08Z')");
            (new DbalOutboxWriter($this->database))->append($this->message());
            (new DbalAuditWriter($this->database))->append(new AuditEntry('00000000-0000-0000-0000-000000000003', null, 'test-worker', '{}', 'account_created', 'account', '00000000-0000-0000-0000-000000000001', null, new \DateTimeImmutable('2026-10-08Z'), 'success'));
        };
        try {
            $transaction->run(function () use ($write): void {
                $write();
                throw new \RuntimeException('Simulated crash before commit');
            });
            self::fail('Crash must propagate');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated crash before commit', $exception->getMessage());
        }
        foreach (['accounts', 'outbox_messages', 'administrative_audit'] as $table) {
            self::assertSame(0, $this->integer($this->database->fetchOne('SELECT count(*) FROM '.$table)));
        }
        $transaction->run($write);
        $observer = DriverManager::getConnection($this->database->getParams());
        foreach (['accounts', 'outbox_messages', 'administrative_audit'] as $table) {
            self::assertSame(1, $this->integer($observer->fetchOne('SELECT count(*) FROM '.$table)));
        }
        $observer->close();
    }

    public function testFailedConsumerRollsBackEffectAndDeduplicationAndRepeatedDeliveryCommitsOnce(): void
    {
        $this->database->executeStatement("INSERT INTO accounts(id,terms_version,terms_accepted_at) VALUES ('00000000-0000-0000-0000-000000000001','v1','2026-10-08Z')");
        $consumer = new DbalEffectConsumer($this->database);
        $now = new \DateTimeImmutable('2026-10-08Z');
        $effect = function (): void {
            $this->database->executeStatement('UPDATE accounts SET version=version+1');
        };
        try {
            $consumer->consume('accounts', 'effect-1', $now, function () use ($effect): void {
                $effect();
                throw new \RuntimeException('Consumer crashed');
            });
            self::fail('Failure must propagate');
        } catch (\RuntimeException $exception) {
            self::assertSame('Consumer crashed', $exception->getMessage());
        }
        self::assertSame(1, $this->integer($this->database->fetchOne('SELECT version FROM accounts')));
        self::assertSame(0, $this->integer($this->database->fetchOne('SELECT count(*) FROM consumed_effects')));
        self::assertTrue($consumer->consume('accounts', 'effect-1', $now, $effect));
        self::assertFalse($consumer->consume('accounts', 'effect-1', $now, $effect));
        self::assertSame(2, $this->integer($this->database->fetchOne('SELECT version FROM accounts')));
        self::assertSame(1, $this->integer($this->database->fetchOne('SELECT count(*) FROM consumed_effects')));
    }

    public function testCommittedOutboxSurvivesDispatchFailureAndIsRetriedOnceDue(): void
    {
        (new DbalTransactionManager($this->database))->run(fn () => (new DbalOutboxWriter($this->database))->append($this->message()));
        $transport = new class implements MessageDispatcher {
            public bool $fail = true;
            /** @var list<OutboxMessage> */
            public array $messages = [];

            public function dispatch(OutboxMessage $message): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('Transport unavailable');
                }
                $this->messages[] = $message;
            }
        };
        $publisher = new DispatchOutbox(new DbalOutboxQueue($this->database), $transport);
        $now = new \DateTimeImmutable('2026-10-08Z');
        self::assertSame(OutboxDispatchOutcome::Deferred, $publisher->dispatchNext($now, 'worker-1'));
        self::assertNull($this->database->fetchOne('SELECT dispatched_at FROM outbox_messages'));
        self::assertNull($this->database->fetchOne('SELECT claimed_by FROM outbox_messages'));
        self::assertNull($this->database->fetchOne('SELECT lease_until FROM outbox_messages'));
        self::assertSame('2026-10-08 00:01:00+00', $this->database->fetchOne('SELECT next_attempt_at FROM outbox_messages'));
        $attempts = $this->database->fetchOne("SELECT delivery_state->'dispatch'->>'attempts' FROM outbox_messages");
        self::assertIsString($attempts);
        self::assertSame('1', $attempts);
        $dispatchState = $this->database->fetchOne("SELECT delivery_state->'dispatch'->>'state' FROM outbox_messages");
        self::assertIsString($dispatchState);
        self::assertSame('pending', $dispatchState);
        $transport->fail = false;
        $empty = $publisher->dispatchNext($now, 'worker-1');
        $dispatched = $publisher->dispatchNext($now->modify('+60 seconds'), 'worker-1');
        $afterwards = $publisher->dispatchNext($now->modify('+120 seconds'), 'worker-1');
        self::assertSame(OutboxDispatchOutcome::Empty, $empty);
        self::assertSame(OutboxDispatchOutcome::Dispatched, $dispatched);
        self::assertSame(OutboxDispatchOutcome::Empty, $afterwards);
        self::assertCount(1, $transport->messages);
        self::assertSame('account.created', $transport->messages[0]->eventType);
        self::assertSame('account-created:1', $transport->messages[0]->effectKey);
        self::assertSame('2026-10-08 00:01:00+00', $this->database->fetchOne('SELECT dispatched_at FROM outbox_messages'));
    }

    public function testFailingIntentIsGivenUpAfterTheMaximumAttemptsAndNeverBlocksLaterIntents(): void
    {
        (new DbalTransactionManager($this->database))->run(fn () => (new DbalOutboxWriter($this->database))->append($this->message()));
        $transport = new class implements MessageDispatcher {
            public bool $fail = true;
            /** @var list<OutboxMessage> */
            public array $messages = [];

            public function dispatch(OutboxMessage $message): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('Transport unavailable');
                }
                $this->messages[] = $message;
            }
        };
        $publisher = new DispatchOutbox(new DbalOutboxQueue($this->database), $transport, 2);
        $now = new \DateTimeImmutable('2026-10-08Z');
        self::assertSame(OutboxDispatchOutcome::Deferred, $publisher->dispatchNext($now, 'worker-1'));
        self::assertSame(OutboxDispatchOutcome::Deferred, $publisher->dispatchNext($now->modify('+60 seconds'), 'worker-1'));
        $dispatchState = $this->database->fetchOne("SELECT delivery_state->'dispatch'->>'state' FROM outbox_messages");
        self::assertIsString($dispatchState);
        self::assertSame('failed', $dispatchState);
        $attempts = $this->database->fetchOne("SELECT delivery_state->'dispatch'->>'attempts' FROM outbox_messages");
        self::assertIsString($attempts);
        self::assertSame('2', $attempts);
        self::assertNull($this->database->fetchOne('SELECT next_attempt_at FROM outbox_messages'));
        $empty = $publisher->dispatchNext($now->modify('+120 seconds'), 'worker-1');
        self::assertSame(OutboxDispatchOutcome::Empty, $empty);
    }

    public function testExpiredOutboxClaimCannotAcknowledgeOrReleaseNewGeneration(): void
    {
        (new DbalTransactionManager($this->database))->run(fn () => (new DbalOutboxWriter($this->database))->append($this->message()));
        $queue = new DbalOutboxQueue($this->database);
        $now = new \DateTimeImmutable('2026-10-08Z');
        $first = $queue->claim($now, 'worker-1');
        self::assertNotNull($first);
        self::assertNull($queue->claim($now, 'worker-2'));
        $second = $queue->claim($now->modify('+60 seconds'), 'worker-2');
        self::assertNotNull($second);
        self::assertSame(1, $first->generation);
        self::assertSame(2, $second->generation);
        self::assertFalse($queue->acknowledge($first, $now));
        self::assertFalse($queue->retry($first, $now, 5));
        self::assertTrue($queue->acknowledge($second, $now->modify('+60 seconds')));
    }

    public function testMessengerDispatchDeliversCommittedMessageAndConsumerSuppressesRedelivery(): void
    {
        $this->database->executeStatement("INSERT INTO accounts(id,terms_version,terms_accepted_at) VALUES ('00000000-0000-0000-0000-000000000001','v1','2026-10-08Z')");
        (new DbalTransactionManager($this->database))->run(fn () => (new DbalOutboxWriter($this->database))->append($this->message()));
        $consumer = new DbalEffectConsumer($this->database);
        $now = new \DateTimeImmutable('2026-10-08Z');
        $handler = function (OutboxMessage $message) use ($consumer, $now): void {
            $consumer->consume('account-handler', $message->effectKey, $now, function (): void {
                $this->database->executeStatement('UPDATE accounts SET version=version+1');
            });
        };
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([OutboxMessage::class => [$handler]]))]);
        $dispatcher = new MessengerMessageDispatcher($bus);
        $publisher = new DispatchOutbox(new DbalOutboxQueue($this->database), $dispatcher);
        self::assertSame(OutboxDispatchOutcome::Dispatched, $publisher->dispatchNext($now, 'messenger-worker'));
        $dispatcher->dispatch($this->message());
        self::assertSame(2, $this->integer($this->database->fetchOne('SELECT version FROM accounts')));
        self::assertSame(1, $this->integer($this->database->fetchOne('SELECT count(*) FROM consumed_effects')));
    }

    public function testLockedOutboxRowIsSkippedByCompetingConnection(): void
    {
        (new DbalTransactionManager($this->database))->run(fn () => (new DbalOutboxWriter($this->database))->append($this->message()));
        $other = DriverManager::getConnection($this->database->getParams());
        $other->executeStatement("SET statement_timeout='200ms'");
        $this->database->beginTransaction();
        $this->database->executeQuery('SELECT id FROM outbox_messages FOR UPDATE')->fetchOne();
        $queue = new DbalOutboxQueue($other);
        self::assertNull($queue->claim(new \DateTimeImmutable('2026-10-08Z'), 'worker-2'));
        $this->database->rollBack();
        self::assertNotNull($queue->claim(new \DateTimeImmutable('2026-10-08Z'), 'worker-2'));
        $other->close();
    }

    public function testCompetingConsumerDeliveryWaitsThenDoesNotRepeatCommittedEffect(): void
    {
        $this->database->executeStatement("INSERT INTO accounts(id,terms_version,terms_accepted_at) VALUES ('00000000-0000-0000-0000-000000000001','v1','2026-10-08Z')");
        $this->database->beginTransaction();
        $consumer = new DbalEffectConsumer($this->database);
        self::assertTrue($consumer->consume('parallel', 'same-effect', new \DateTimeImmutable('2026-10-08Z'), function (): void {
            $this->database->executeStatement('UPDATE accounts SET version=version+1');
        }));
        $code = <<<'WORKER'
require 'vendor/autoload.php';
$parameters = json_decode(getenv('OPERATIONS_DB_PARAMS'), true, 512, JSON_THROW_ON_ERROR);
$connection = \Doctrine\DBAL\DriverManager::getConnection($parameters);
$connection->executeStatement("SET statement_timeout='5000ms'");
echo "READY\n";
$consumer = new \App\Infrastructure\Operations\DbalEffectConsumer($connection);
$result = $consumer->consume('parallel', 'same-effect', new \DateTimeImmutable('2026-10-08Z'), function () use ($connection): void {
    $connection->executeStatement('UPDATE accounts SET version=version+1');
});
echo $result ? 'EXECUTED' : 'DEDUPLICATED';
WORKER;
        $process = new Process(['php', '-r', $code], self::getContainer()->getParameter('kernel.project_dir'), ['OPERATIONS_DB_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR)]);
        $process->start();
        self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY')));
        self::assertTrue($process->isRunning());
        $this->database->commit();
        self::assertSame(0, $process->wait());
        self::assertSame("READY\nDEDUPLICATED", $process->getOutput());
        self::assertSame(2, $this->integer($this->database->fetchOne('SELECT version FROM accounts')));
        self::assertSame(1, $this->integer($this->database->fetchOne('SELECT count(*) FROM consumed_effects')));
    }

    public function testOutboxAndAuditRequireAnOwningBusinessTransaction(): void
    {
        $operations = [
            fn () => (new DbalOutboxWriter($this->database))->append($this->message()),
            fn () => (new DbalAuditWriter($this->database))->append(new AuditEntry('00000000-0000-0000-0000-000000000003', null, 'test-worker', '{}', 'account_created', 'account', '00000000-0000-0000-0000-000000000001', null, new \DateTimeImmutable('2026-10-08Z'), 'success')),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('An independent write must not commit');
            } catch (\LogicException $exception) {
                self::assertSame('An owning business transaction is required', $exception->getMessage());
            }
        }
        self::assertSame(0, $this->integer($this->database->fetchOne('SELECT count(*) FROM outbox_messages')));
        self::assertSame(0, $this->integer($this->database->fetchOne('SELECT count(*) FROM administrative_audit')));
    }

    public function testAcceptedMessengerDeliveryBeforeLostAcknowledgementIsSafeToReplay(): void
    {
        $this->database->executeStatement("INSERT INTO accounts(id,terms_version,terms_accepted_at) VALUES ('00000000-0000-0000-0000-000000000001','v1','2026-10-08Z')");
        (new DbalTransactionManager($this->database))->run(fn () => (new DbalOutboxWriter($this->database))->append($this->message()));
        $consumer = new DbalEffectConsumer($this->database);
        $now = new \DateTimeImmutable('2026-10-08Z');
        $handler = function (OutboxMessage $message) use ($consumer, $now): void {
            $consumer->consume('account-handler', $message->effectKey, $now, function (): void {
                $this->database->executeStatement('UPDATE accounts SET version=version+1');
            });
        };
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([OutboxMessage::class => [$handler]]))]);
        $transport = new class(new MessengerMessageDispatcher($bus), $this->database) implements MessageDispatcher {
            public bool $fail = true;

            public function __construct(private MessageDispatcher $dispatcher, private Connection $connection)
            {
            }

            public function dispatch(OutboxMessage $message): void
            {
                TestCase::assertFalse($this->connection->isTransactionActive(), 'Delivery must be outside the business/claim transaction');
                $this->dispatcher->dispatch($message);
                if ($this->fail) {
                    $this->fail = false;
                    throw new \RuntimeException('Acknowledgement lost after acceptance');
                }
            }
        };
        $publisher = new DispatchOutbox(new DbalOutboxQueue($this->database), $transport);
        self::assertSame(OutboxDispatchOutcome::Deferred, $publisher->dispatchNext($now, 'worker-1'));
        self::assertNull($this->database->fetchOne('SELECT dispatched_at FROM outbox_messages'));
        self::assertSame(OutboxDispatchOutcome::Dispatched, $publisher->dispatchNext($now->modify('+60 seconds'), 'worker-2'));
        self::assertSame(2, $this->integer($this->database->fetchOne('SELECT version FROM accounts')));
        self::assertSame(1, $this->integer($this->database->fetchOne('SELECT count(*) FROM consumed_effects')));
    }

    private function integer(mixed $value): int
    {
        self::assertTrue(\is_int($value) || \is_string($value));

        return (int) $value;
    }

    private function message(): OutboxMessage
    {
        return new OutboxMessage('00000000-0000-0000-0000-000000000002', 'account-created:1', 'account', '00000000-0000-0000-0000-000000000001', 1, 'account.created', 1, '{"account_id":"00000000-0000-0000-0000-000000000001"}', new \DateTimeImmutable('2026-10-08Z'));
    }
}
