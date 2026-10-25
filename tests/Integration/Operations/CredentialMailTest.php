<?php

declare(strict_types=1);

namespace App\Tests\Integration\Operations;

use App\Application\Operations\CredentialMail;
use App\Application\Operations\DeliverCredentialMail;
use App\Application\Operations\OutboxMessage;
use App\Application\Operations\UncertainMailDelivery;
use App\Infrastructure\Operations\AesCredentialTokenProtector;
use App\Infrastructure\Operations\CredentialMailMessageHandler;
use App\Infrastructure\Operations\DbalCredentialDeliveryStore;
use App\Infrastructure\Operations\DbalOutboxWriter;
use App\Infrastructure\Operations\DbalTransactionManager;
use App\Infrastructure\Operations\SymfonyCredentialMailSender;
use App\Tests\Support\IsolatedDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Process\Process;

final class CredentialMailTest extends KernelTestCase
{
    private Connection $admin;
    private Connection $database;
    private string $databaseName;
    private string $databaseUrl;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = self::getContainer()->get(Connection::class);
        $this->databaseName = 'mail_test_'.bin2hex(random_bytes(6));
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

    public function testCredentialMailIsAcceptedByRealMailpitWithTrustedVerificationLink(): void
    {
        $this->seed();
        $now = new \DateTimeImmutable('2026-10-08Z');
        $mail = new CredentialMail('runner-'.$this->databaseName.'@example.test', 'verification', str_repeat('a', 64), $now->modify('+1 hour'));
        $sender = new SymfonyCredentialMailSender(Transport::fromDsn('smtp://mailpit:1025'), 'http://localhost:8082', 'no-reply@example.test');
        $delivery = new DeliverCredentialMail(new DbalCredentialDeliveryStore($this->database), $sender);
        self::assertTrue($delivery->deliver('mail:'.$this->databaseName, $mail, $now));
        self::assertFalse($delivery->deliver('mail:'.$this->databaseName, $mail, $now));
        self::assertSame('accepted', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
        $response = file_get_contents('http://mailpit:8025/api/v1/messages');
        self::assertIsString($response);
        $messages = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        self::assertIsArray($messages['messages']);
        $matched = [];
        foreach ($messages['messages'] as $message) {
            self::assertIsArray($message);
            if (($message['MessageID'] ?? null) === hash('sha256', 'mail:'.$this->databaseName).'@runnerbib.local') {
                $matched[] = $message;
            }
        }
        self::assertCount(1, $matched);
        self::assertIsString($matched[0]['ID']);
        $body = file_get_contents('http://mailpit:8025/api/v1/message/'.$matched[0]['ID']);
        self::assertIsString($body);
        self::assertStringContainsString('http://localhost:8082/verification/'.str_repeat('a', 64), $body);
    }

    public function testUncertainSmtpOutcomeIsRecordedAndAutomaticRedeliveryDoesNotSendAgain(): void
    {
        $this->seed();
        $sender = $this->failingSender();
        $now = new \DateTimeImmutable('2026-10-08Z');
        $mail = new CredentialMail('local@example.test', 'verification', str_repeat('b', 64), $now->modify('+1 hour'));
        $delivery = new DeliverCredentialMail(new DbalCredentialDeliveryStore($this->database), $sender);
        for ($i = 0; $i < 2; ++$i) {
            try {
                $delivery->deliver('mail:'.$this->databaseName, $mail, $now);
                self::fail('Uncertain SMTP outcome requires reconciliation');
            } catch (UncertainMailDelivery $exception) {
                self::assertSame('Credential mail outcome is uncertain; reconcile the stable Message-ID before retrying.', $exception->getMessage());
                self::assertNull($exception->getPrevious());
                self::assertSame('unknown', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
            }
        }
        self::assertSame(1, $sender->calls);
        self::assertSame('unknown', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
    }

    public function testCrashAfterSendingIntentBecomesUnknownWithoutResending(): void
    {
        $this->seed();
        $sender = $this->failingSender();
        $now = new \DateTimeImmutable('2026-10-08Z');
        $store = new DbalCredentialDeliveryStore($this->database);
        self::assertTrue($store->claim('mail:'.$this->databaseName, 'stable-message@runnerbib.local', $now));
        $delivery = new DeliverCredentialMail($store, $sender);
        try {
            $delivery->deliver('mail:'.$this->databaseName, new CredentialMail('local@example.test', 'verification', str_repeat('c', 64), $now->modify('+1 hour')), $now);
            self::fail('A lost process outcome must require reconciliation');
        } catch (UncertainMailDelivery) {
        }
        self::assertSame(0, $sender->calls);
        self::assertSame('unknown', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
    }

    public function testExpiredCredentialIsNotSentOrReactivated(): void
    {
        $this->seed();
        $sender = $this->failingSender();
        $now = new \DateTimeImmutable('2026-10-08Z');
        $delivery = new DeliverCredentialMail(new DbalCredentialDeliveryStore($this->database), $sender);
        self::assertFalse($delivery->deliver('mail:'.$this->databaseName, new CredentialMail('local@example.test', 'verification', str_repeat('d', 64), $now), $now));
        self::assertFalse($delivery->deliver('mail:'.$this->databaseName, new CredentialMail('local@example.test', 'verification', str_repeat('d', 64), $now->modify('+1 hour')), $now));
        self::assertSame(0, $sender->calls);
        self::assertSame('expired', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
    }

    public function testMissingMailIntentCannotSend(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Credential mail intent is missing');
        (new DbalCredentialDeliveryStore($this->database))->claim('missing-effect', 'message@runnerbib.local', new \DateTimeImmutable('2026-10-08Z'));
    }

    public function testDeliveryProjectionMigrationRollsBackAndReplaysWithoutDeletingIntent(): void
    {
        $this->seed();
        $this->migrate('DoctrineMigrations\\Version20261008000200');
        self::assertNull($this->database->fetchOne("SELECT column_name FROM information_schema.columns WHERE table_name='outbox_messages' AND column_name='delivery_state'") ?: null);
        self::assertSame('mail:'.$this->databaseName, $this->database->fetchOne('SELECT effect_key FROM outbox_messages'));
        $this->migrate('latest');
        self::assertSame('{}', $this->database->fetchOne('SELECT delivery_state::text FROM outbox_messages'));
    }

    #[DataProvider('invalidMessages')]
    public function testInvalidOrUnsupportedMessageCannotSend(string $eventType, int $version, string $payload): void
    {
        $this->seed();
        $sender = $this->failingSender();
        $handler = new CredentialMailMessageHandler(new DeliverCredentialMail(new DbalCredentialDeliveryStore($this->database), $sender), $this->protector());
        try {
            $handler(new OutboxMessage('00000000-0000-0000-0000-000000000001', 'mail:'.$this->databaseName, 'identity_mail', '00000000-0000-0000-0000-000000000001', 1, $eventType, $version, $payload, new \DateTimeImmutable('2026-10-08Z')));
            self::fail('Invalid message must fail safely');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Unsupported or invalid credential mail message', $exception->getMessage());
        }
        self::assertSame(0, $sender->calls);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidMessages(): iterable
    {
        $valid = ['email' => 'local@example.test', 'purpose' => 'verification', 'token' => self::protectedToken(str_repeat('e', 64)), 'expires_at' => '2026-10-08T01:00:00+00:00'];
        yield 'unknown event' => ['unknown.event', 1, json_encode($valid, JSON_THROW_ON_ERROR)];
        yield 'unsupported version' => ['identity.credential_mail', 2, json_encode($valid, JSON_THROW_ON_ERROR)];
        yield 'invalid JSON' => ['identity.credential_mail', 1, '{'];
        yield 'missing fields' => ['identity.credential_mail', 1, '{}'];
        yield 'unreadable token' => ['identity.credential_mail', 1, json_encode(array_replace($valid, ['token' => 'invalid']), JSON_THROW_ON_ERROR)];
        yield 'token is not a credential' => ['identity.credential_mail', 1, json_encode(array_replace($valid, ['token' => self::protectedToken('not-a-credential-token')]), JSON_THROW_ON_ERROR)];
        foreach (['email' => 'invalid', 'purpose' => 'admin_impersonation', 'expires_at' => 'not-a-date'] as $field => $value) {
            yield 'invalid '.$field => ['identity.credential_mail', 1, json_encode(array_replace($valid, [$field => $value]), JSON_THROW_ON_ERROR)];
        }
    }

    public function testHandlerSendsRealPasswordResetLink(): void
    {
        $this->seed();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $sender = new SymfonyCredentialMailSender(Transport::fromDsn('smtp://mailpit:1025'), 'http://localhost:8082', 'no-reply@example.test');
        $handler = new CredentialMailMessageHandler(new DeliverCredentialMail(new DbalCredentialDeliveryStore($this->database), $sender), $this->protector());
        $payload = json_encode(['email' => 'reset-'.$this->databaseName.'@example.test', 'purpose' => 'password_reset', 'token' => self::protectedToken(str_repeat('f', 64)), 'expires_at' => $now->modify('+1 hour')->format(DATE_ATOM)], JSON_THROW_ON_ERROR);
        $handler(new OutboxMessage('00000000-0000-0000-0000-000000000001', 'mail:'.$this->databaseName, 'identity_mail', '00000000-0000-0000-0000-000000000001', 1, 'identity.credential_mail', 1, $payload, $now));
        self::assertSame('accepted', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
        $response = file_get_contents('http://mailpit:8025/api/v1/messages');
        self::assertIsString($response);
        $messages = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        self::assertIsArray($messages['messages']);
        foreach ($messages['messages'] as $message) {
            self::assertIsArray($message);
            if (($message['MessageID'] ?? null) === hash('sha256', 'mail:'.$this->databaseName).'@runnerbib.local') {
                self::assertIsString($message['ID']);
                $body = file_get_contents('http://mailpit:8025/api/v1/message/'.$message['ID']);
                self::assertIsString($body);
                self::assertStringContainsString('http://localhost:8082/reinitialiser/'.str_repeat('f', 64), $body);

                return;
            }
        }
        self::fail('Reset mail was not captured');
    }

    public function testConfiguredOutboxPublisherAndWorkerDeliverCredentialWithoutLoggingSecrets(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $token = str_repeat('1', 64);
        $email = 'pipeline-'.$this->databaseName.'@example.test';
        $this->seed(json_encode(['email' => $email, 'purpose' => 'verification', 'token' => self::protectedToken($token), 'expires_at' => $now->modify('+1 hour')->format(DATE_ATOM)], JSON_THROW_ON_ERROR));
        $environment = ['DATABASE_URL' => $this->databaseUrl, 'APP_ENV' => 'test', 'APP_BASE_URL' => 'http://localhost:8082', 'MAILER_FROM' => 'no-reply@example.test'];
        $publish = new Process(['php', 'bin/console', 'app:outbox:dispatch', '--limit=1', '--no-interaction'], self::getContainer()->getParameter('kernel.project_dir'), $environment);
        $publish->mustRun();
        self::assertStringContainsString('Queued messages: 1', $publish->getOutput());
        $worker = new Process(['php', 'bin/console', 'messenger:consume', 'async', '--limit=1', '--time-limit=5', '--sleep=0.01', '--no-interaction'], self::getContainer()->getParameter('kernel.project_dir'), $environment);
        $worker->setTimeout(10);
        $worker->mustRun();
        self::assertSame('accepted', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM messenger_messages'));
        $output = $publish->getOutput().$publish->getErrorOutput().$worker->getOutput().$worker->getErrorOutput();
        self::assertStringNotContainsString($token, $output);
        self::assertStringNotContainsString($email, $output);
    }

    private function failingSender(): FailingCredentialMailSender
    {
        return new FailingCredentialMailSender($this->database);
    }

    private function protector(): AesCredentialTokenProtector
    {
        return new AesCredentialTokenProtector(self::secret());
    }

    public static function protectedToken(string $token): string
    {
        return (new AesCredentialTokenProtector(self::secret()))->protect($token);
    }

    private static function secret(): string
    {
        $secret = $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? null;

        return \is_string($secret) && '' !== $secret ? $secret : 'credential-token-secret-for-tests-0123456789';
    }

    private function seed(string $payload = '{}'): void
    {
        $message = new OutboxMessage('00000000-0000-0000-0000-000000000001', 'mail:'.$this->databaseName, 'identity_mail', '00000000-0000-0000-0000-000000000001', 1, 'identity.credential_mail', 1, $payload, new \DateTimeImmutable('2026-10-08Z'));
        (new DbalTransactionManager($this->database))->run(fn () => (new DbalOutboxWriter($this->database))->append($message));
    }

    private function migrate(string $version): void
    {
        $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', $version, '--no-interaction'], self::getContainer()->getParameter('kernel.project_dir'), ['DATABASE_URL' => $this->databaseUrl]);
        $process->mustRun();
    }
}
