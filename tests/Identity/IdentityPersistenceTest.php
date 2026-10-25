<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Infrastructure\Identity\DbalIdentityStore;
use App\Tests\Support\IsolatedDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IdentityPersistenceTest extends KernelTestCase
{
    private Connection $admin;
    private Connection $database;
    private string $databaseName;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = self::getContainer()->get(Connection::class);
        $this->databaseName = 'identity_test_'.bin2hex(random_bytes(6));
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($url);
        IsolatedDatabase::create($this->admin, $this->databaseName, dirname(__DIR__, 2), $url);
        $parameters = $this->admin->getParams();
        $parameters['dbname'] = $this->databaseName;
        $this->database = DriverManager::getConnection($parameters);
        $databaseUrl = preg_replace('~/[^/?]+(?=\?|$)~', '/'.$this->databaseName, $url);
        self::assertIsString($databaseUrl);
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->admin->executeStatement('DROP DATABASE '.$this->databaseName.' WITH (FORCE)');
        parent::tearDown();
    }

    public function testRegistrationEmitsVerificationAndResetWithoutDisclosingDuplicates(): void
    {
        $effects = new RecordingEffects();
        $registration = new \App\Application\Identity\RegisterAccount(new DbalIdentityStore($this->database), $effects, new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $registration->register('Runner@Example.test', 'correct horse battery staple', true);
        self::assertSame(1, $effects->messageCount());
        self::assertSame(['runner@example.test', 'verification', 'raw-token'], $effects->lastMessage());
        self::assertSame('account.registered', $this->database->fetchOne('SELECT action FROM administrative_audit'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        $registration->register('Runner@Example.test', 'correct horse battery staple', true);
        self::assertSame(1, $effects->messageCount());
        self::assertTrue($registration->verify('raw-token'));
        self::assertFalse($registration->verify('raw-token'));
        $registration->requestReset('unknown@example.test');
        self::assertSame(1, $effects->messageCount());
        $registration->requestReset('runner@example.test');
        self::assertSame(2, $effects->messageCount());
        self::assertTrue($registration->reset('raw-token-2', 'another long password'));
        self::assertFalse($registration->reset('raw-token-2', 'another long password'));
        self::assertFalse($registration->reset('anything', 'short'));
    }

    public function testVerificationCanBeResentWithoutReopeningVerifiedAccountOrLeakingUnknownEmail(): void
    {
        $effects = new RecordingEffects();
        $registration = new \App\Application\Identity\RegisterAccount(new DbalIdentityStore($this->database), $effects, new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $registration->register('runner@example.test', 'correct horse battery staple', true);
        $registration->requestVerification('missing@example.test');
        self::assertSame(1, $effects->messageCount());
        $registration->requestVerification('runner@example.test');
        self::assertSame(2, $effects->messageCount());
        self::assertSame(['runner@example.test', 'verification', 'raw-token-2'], $effects->lastMessage());
        self::assertTrue($registration->verify('raw-token-2'));
        self::assertFalse($registration->verify('raw-token'));
        $registration->requestVerification('runner@example.test');
        self::assertSame(2, $effects->messageCount());
    }

    public function testCredentialIssuanceRevalidatesAuthoritativeAccountState(): void
    {
        $store = new DbalIdentityStore($this->database);
        $now = new \DateTimeImmutable('2026-10-09T12:00:00Z');
        $store->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', $now);
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='suspended'");
        self::assertFalse($store->issueCredential('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000001', 'password_reset', 'token-hash', $now, $now->modify('+1 hour')));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM account_credentials'));
    }

    public function testDeliveryFailureRollsBackAccountAndCredential(): void
    {
        $effects = new class extends RecordingEffects {
            public function deliver(string $email, string $purpose, string $token, \DateTimeImmutable $expiresAt): void
            {
                throw new \RuntimeException('outbox failure');
            }
        };
        $store = new DbalIdentityStore($this->database);
        $registration = new \App\Application\Identity\RegisterAccount($store, $effects, new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        try {
            $registration->register('runner@example.test', 'correct horse battery staple', true);
            self::fail('Expected failed outbox transaction');
        } catch (\RuntimeException $error) {
            self::assertSame('outbox failure', $error->getMessage());
        }
        self::assertNull($store->findActive('runner@example.test'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM account_credentials'));
    }

    public function testRuntimeEffectsHashPasswordsAndQueuePrivateCredentialMail(): void
    {
        $protector = new \App\Infrastructure\Operations\AesCredentialTokenProtector('credential-token-secret-for-tests-0123456789');
        $effects = new \App\Infrastructure\Identity\OutboxIdentityEffects(new \App\Infrastructure\Operations\DbalOutboxWriter($this->database), new \Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory([\App\Infrastructure\Identity\SecurityUser::class => ['algorithm' => 'argon2id']]), $protector);
        $hash = $effects->hashPassword('long strong secret');
        self::assertTrue(password_verify('long strong secret', $hash));
        self::assertFalse(password_verify('different secret', $hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $effects->newId());
        self::assertSame(64, strlen($effects->token()));
        self::assertLessThan(5, abs(time() - $effects->now()->getTimestamp()));
        $this->database->transactional(fn () => $effects->deliver('runner@example.test', 'verification', 'private-token', new \DateTimeImmutable('2026-10-08T13:00:00Z')));
        $row = $this->database->fetchAssociative('SELECT event_type,payload FROM outbox_messages');
        self::assertNotFalse($row);
        self::assertSame('identity.credential_mail', $row['event_type']);
        self::assertIsString($row['payload']);
        $payload = json_decode($row['payload'], true);
        self::assertIsArray($payload);
        self::assertSame('runner@example.test', $payload['email']);
        self::assertSame('verification', $payload['purpose']);
        self::assertSame('2026-10-08T13:00:00+00:00', $payload['expires_at']);
        self::assertNotSame('private-token', $payload['token']);
        self::assertStringNotContainsString('private-token', $row['payload']);
        self::assertIsString($payload['token']);
        self::assertSame('private-token', $protector->reveal($payload['token']));
    }

    public function testProviderRejectsForeignUserObjects(): void
    {
        $provider = new \App\Infrastructure\Identity\IdentityUserProvider(new DbalIdentityStore($this->database));
        $this->expectException(\Symfony\Component\Security\Core\Exception\UnsupportedUserException::class);
        $provider->refreshUser(new \Symfony\Component\Security\Core\User\InMemoryUser('foreign', null));
    }

    public function testProviderRefreshRevokesSuspendedAccount(): void
    {
        $store = new DbalIdentityStore($this->database);
        $store->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-08Z'));
        $provider = new \App\Infrastructure\Identity\IdentityUserProvider($store);
        $user = $provider->loadUserByIdentifier('runner@example.test');
        self::assertSame('runner@example.test', $user->getUserIdentifier());
        self::assertTrue($provider->supportsClass(\App\Infrastructure\Identity\SecurityUser::class));
        self::assertFalse($provider->supportsClass(\stdClass::class));
        self::assertSame('runner@example.test', $provider->refreshUser($user)->getUserIdentifier());
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='suspended'");
        $this->expectException(\Symfony\Component\Security\Core\Exception\UserNotFoundException::class);
        $provider->refreshUser($user);
    }

    public function testVerificationAndRecoveryAreAtomicSingleUse(): void
    {
        $store = new DbalIdentityStore($this->database);
        $now = new \DateTimeImmutable('2026-10-08T12:00:00Z');
        $id = '00000000-0000-0000-0000-000000000001';
        self::assertTrue($store->register($id, 'Runner@Example.test', 'hash-one', 'terms-v1', $now));
        self::assertFalse($store->register('00000000-0000-0000-0000-000000000002', 'runner@example.test', 'hash-two', 'terms-v1', $now));
        self::assertNull($store->findActive('missing@example.test'));
        $account = $store->findActive('runner@example.test');
        self::assertNotNull($account);
        self::assertNull($account['verified_at']);
        $store->issueCredential('00000000-0000-0000-0000-000000000003', $id, 'verification', 'verify-hash', $now, $now->modify('+1 hour'));
        self::assertFalse($store->consumeCredential('verify-hash', 'password_reset', $now, 'new-hash'));
        self::assertTrue($store->consumeCredential('verify-hash', 'verification', $now, null));
        self::assertFalse($store->consumeCredential('verify-hash', 'verification', $now, null));
        $account = $store->findActive('runner@example.test');
        self::assertNotNull($account);
        self::assertNotNull($account['verified_at']);
        $store->issueCredential('00000000-0000-0000-0000-000000000004', $id, 'password_reset', 'reset-hash', $now, $now->modify('+1 hour'));
        self::assertFalse($store->consumeCredential('reset-hash', 'password_reset', $now->modify('+1 hour'), 'new-hash'));
        self::assertTrue($store->consumeCredential('reset-hash', 'password_reset', $now, 'new-hash'));
        $account = $store->findActive('runner@example.test');
        self::assertNotNull($account);
        self::assertSame('new-hash', $account['password_hash']);
        self::assertSame(3, $account['version']);
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='suspended' WHERE id=?", [$id]);
        self::assertNull($store->findActive('runner@example.test'));
        self::assertFalse($store->consumeCredential('missing', 'verification', $now, null));
    }

    public function testRecoveryNormalizesTheRecipientAndEnforcesBothPasswordLengthEdges(): void
    {
        $effects = new RecordingEffects();
        $registration = new \App\Application\Identity\RegisterAccount(new DbalIdentityStore($this->database), $effects, new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $registration->register('runner@example.test', 'original password', true);
        $registration->requestVerification(' RUNNER@EXAMPLE.TEST ');
        self::assertSame(['runner@example.test', 'verification', 'raw-token-2'], $effects->lastMessage());
        self::assertTrue($registration->verify('raw-token-2'));
        $registration->requestReset(' RUNNER@EXAMPLE.TEST ');
        self::assertSame(['runner@example.test', 'password_reset', 'raw-token-3'], $effects->lastMessage());
        self::assertFalse($registration->reset('raw-token-3', str_repeat('x', 11)));
        self::assertFalse($registration->reset('raw-token-3', str_repeat('x', 4097)));
        self::assertTrue($registration->reset('raw-token-3', str_repeat('x', 12)));
        $registration->requestReset('runner@example.test');
        self::assertTrue($registration->reset('raw-token-4', str_repeat('x', 4096)));
    }
}

class RecordingEffects implements \App\Application\Identity\IdentityEffects
{
    /** @var list<array{string,string,string}> */
    public array $messages = [];
    private int $sequence = 10;

    public function messageCount(): int
    {
        return count($this->messages);
    }

    /** @return array{string,string,string}|null */
    public function lastMessage(): ?array
    {
        return $this->messages[array_key_last($this->messages)] ?? null;
    }

    public function newId(): string
    {
        return sprintf('00000000-0000-0000-0000-%012d', $this->sequence++);
    }

    public function token(): string
    {
        return [] === $this->messages ? 'raw-token' : 'raw-token-'.(count($this->messages) + 1);
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-08T12:00:00Z');
    }

    public function deliver(string $email, string $purpose, string $token, \DateTimeImmutable $expiresAt): void
    {
        $this->messages[] = [$email, $purpose, $token];
    }
}
