<?php

declare(strict_types=1);

namespace App\Tests\Integration\Privacy;

use App\Tests\Support\IsolatedWebTestCase;

final class PrivacyRequestTest extends IsolatedWebTestCase
{
    private const ACCOUNT = '00000000-0000-0000-0000-000000000001';
    private const REQUEST = '00000000-0000-0000-0000-000000000070';

    public function testAuthenticatedExportRequestHasDurablePendingProgressWithoutPretendingCompletion(): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register(self::ACCOUNT, 'private@example.test', 'hash', 'test-terms', new \DateTimeImmutable('2026-10-12Z'));
        $manager = $this->manager(self::ACCOUNT);
        $now = new \DateTimeImmutable('2026-10-12T10:00:00Z');
        $receipt = $manager->request(self::REQUEST, 'access_export', $now);
        self::assertNotNull($receipt);
        self::assertSame('pending', $receipt->request->state);
        self::assertSame('access_export', $receipt->request->kind);
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='privacy.requested'"));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='privacy.requested'"));
        $replay = $manager->request(self::REQUEST, 'access_export', $now->modify('+10 seconds'));
        self::assertNotNull($replay);
        self::assertSame($receipt->statusToken, $replay->statusToken);
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        $this->database->executeStatement("UPDATE outbox_messages SET dispatched_at='2026-10-12T10:00:20Z' WHERE event_type='privacy.requested'");
        self::assertSame('pending', $manager->status($receipt->statusToken, $now->modify('+30 seconds'))->state);
        self::assertSame('active', $this->database->fetchOne('SELECT lifecycle_state FROM accounts'));
        $payload = $this->database->fetchOne('SELECT payload::text FROM outbox_messages');
        self::assertIsString($payload);
        self::assertStringNotContainsString('private@example.test', $payload);
        self::assertStringNotContainsString($receipt->statusToken, $payload);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deletionOwners')]
    public function testDeletionIntakeFencesOnlyTheOwnerAndPreservesProtectedMoney(string $owner): void
    {
        $fixture = file_get_contents(dirname(__DIR__, 2).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE checkouts SET state='processing'");
        $this->database->executeStatement("INSERT INTO account_private_profiles(account_id,email,email_normalized) VALUES (?,'privacy-owner@example.test','privacy-owner@example.test')", [$owner]);
        $this->database->executeStatement("INSERT INTO account_credentials(id,account_id,purpose,token_hash,created_at,expires_at) VALUES('00000000-0000-0000-0000-000000000065',?,'verification','test-only-token-hash','2026-10-12Z','2026-10-13Z')", [$owner]);
        $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,seller_id,price_cents) VALUES('00000000-0000-0000-0000-000000000080','00000000-0000-0000-0000-000000000005','00000000-0000-0000-0000-000000000001',5000)");
        $now = new \DateTimeImmutable('2026-10-12T10:00:00Z');
        $receipt = $this->manager($owner)->request(self::REQUEST, 'deletion', $now);
        self::assertNotNull($receipt);
        self::assertSame('blocked', $receipt->request->state);
        self::assertSame(['retention_policy', 'financial_resolution'], $receipt->request->blockers);
        self::assertSame('suspended', $this->database->fetchOne('SELECT lifecycle_state FROM accounts WHERE id=?', [$owner]));
        self::assertSame(2, $this->database->fetchOne('SELECT version FROM accounts WHERE id=?', [$owner]));
        self::assertNotNull($this->database->fetchOne('SELECT consumed_at FROM account_credentials'));
        self::assertNull($this->database->fetchOne('SELECT anonymized_at FROM accounts WHERE id=?', [$owner]));
        self::assertSame('privacy-owner@example.test', $this->database->fetchOne('SELECT email FROM account_private_profiles'));
        self::assertSame(self::ACCOUNT === $owner ? 'withdrawn' : 'published', $this->database->fetchOne("SELECT state FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000009'"));
        self::assertSame(self::ACCOUNT === $owner ? 'withdrawn' : 'draft', $this->database->fetchOne("SELECT state FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000080'"));
        self::assertSame('processing', $this->database->fetchOne('SELECT state FROM checkouts'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertNotNull($this->database->fetchOne('SELECT cancellation_requested_at FROM checkouts'));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='checkout.cancellation_requested'"));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM financial_operations'));
        self::assertSame('unknown', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
        self::assertSame('blocked', $this->manager(null)->status($receipt->statusToken, $now->modify('+1 second'))->state);
        self::assertSame($receipt->statusToken, $this->manager($owner)->request(self::REQUEST, 'deletion', $now->modify('+2 seconds'))?->statusToken);
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='privacy.requested'"));
    }

    public function testStatusAuthorityCannotExtendTheRecordedExpiry(): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register(self::ACCOUNT, 'private@example.test', 'hash', 'test-terms', new \DateTimeImmutable('2026-10-12Z'));
        $now = new \DateTimeImmutable('2026-10-12T10:00:00Z');
        $manager = $this->manager(self::ACCOUNT);
        self::assertNotNull($manager->request(self::REQUEST, 'access_export', $now));
        $extended = (new \App\Infrastructure\Privacy\HmacPrivacyRequestTokens('test-only-privacy-secret'))->issue(self::REQUEST, self::ACCOUNT, 1, $now->modify('+1 hour'));
        $this->expectException(\DomainException::class);
        $manager->status($extended, $now->modify('+2 minutes'));
    }

    public function testUnconfiguredPolicyRefusesDeletionBeforeAnyMutation(): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register(self::ACCOUNT, 'private@example.test', 'hash', 'test-terms', new \DateTimeImmutable('2026-10-12Z'));
        try {
            $this->manager(self::ACCOUNT, null)->request(self::REQUEST, 'deletion', new \DateTimeImmutable('2026-10-12Z'));
            self::fail('Missing policy must refuse intake.');
        } catch (\App\Application\Privacy\UnconfiguredPrivacyProgressPolicy) {
            self::assertSame('active', $this->database->fetchOne('SELECT lifecycle_state FROM accounts'));
            self::assertSame(1, $this->database->fetchOne('SELECT version FROM accounts'));
            self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='privacy.requested'"));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        }
    }

    public function testUnprotectedDeletionPreservesDataAndInvalidatesEarlierStatusAuthority(): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register(self::ACCOUNT, 'private@example.test', 'hash', 'test-terms', new \DateTimeImmutable('2026-10-12Z'));
        $now = new \DateTimeImmutable('2026-10-12T10:00:00Z');
        $manager = $this->manager(self::ACCOUNT);
        $export = $manager->request('00000000-0000-0000-0000-000000000071', 'access_export', $now);
        self::assertNotNull($export);
        $deletion = $manager->request(self::REQUEST, 'deletion', $now);
        self::assertNotNull($deletion);
        self::assertSame(['retention_policy'], $deletion->request->blockers);
        self::assertSame('blocked', $manager->status($deletion->statusToken, $now)->state);
        self::assertSame('private@example.test', $this->database->fetchOne('SELECT email FROM account_private_profiles'));
        $this->expectException(\DomainException::class);
        $manager->status($export->statusToken, $now);
    }

    /** @return iterable<string,array{string}> */
    public static function deletionOwners(): iterable
    {
        yield 'seller' => [self::ACCOUNT];
        yield 'buyer' => ['00000000-0000-0000-0000-000000000002'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRequests')]
    public function testInvalidRequestsLeaveNoDurableIntent(string $id, string $kind): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register(self::ACCOUNT, 'private@example.test', 'hash', 'test-terms', new \DateTimeImmutable('2026-10-12Z'));
        try {
            $this->manager(self::ACCOUNT)->request($id, $kind, new \DateTimeImmutable('2026-10-12Z'));
            self::fail('Invalid request accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
            self::assertSame('active', $this->database->fetchOne('SELECT lifecycle_state FROM accounts'));
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidRequests(): iterable
    {
        yield 'unknown kind' => [self::REQUEST, 'purge'];
        yield 'leading garbage' => ['x'.self::REQUEST, 'access_export'];
        yield 'trailing garbage' => [self::REQUEST.'x', 'access_export'];
        yield 'trailing newline' => [self::REQUEST."\n", 'access_export'];
        yield 'empty id' => ['', 'access_export'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLifetimes')]
    public function testNonpositiveLifetimeRefusesBeforeMutation(int $lifetime): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register(self::ACCOUNT, 'private@example.test', 'hash', 'test-terms', new \DateTimeImmutable('2026-10-12Z'));
        $this->expectException(\App\Application\Privacy\UnconfiguredPrivacyProgressPolicy::class);
        $this->manager(self::ACCOUNT, $lifetime)->request(self::REQUEST, 'access_export', new \DateTimeImmutable('2026-10-12Z'));
    }

    /** @return iterable<string,array{int}> */
    public static function invalidLifetimes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testOneSecondLifetimeAndCorrectionReceiptKeepExactRequestContext(): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register(self::ACCOUNT, 'private@example.test', 'hash', 'test-terms', new \DateTimeImmutable('2026-10-12Z'));
        $now = new \DateTimeImmutable('2026-10-12T10:00:00.123456Z');
        $manager = $this->manager(self::ACCOUNT, 1);
        $receipt = $manager->request(self::REQUEST, 'correction', $now);
        self::assertNotNull($receipt);
        self::assertSame(self::REQUEST, $receipt->request->requestId);
        self::assertSame(self::ACCOUNT, $receipt->request->accountId);
        self::assertSame('correction', $receipt->request->kind);
        self::assertSame('pending', $receipt->request->state);
        self::assertSame(1, $receipt->request->accountVersion);
        self::assertSame([], $receipt->request->blockers);
        self::assertSame('2026-10-12T10:00:01.123456+00:00', $receipt->request->authorityExpiresAt->format('Y-m-d\\TH:i:s.uP'));
        self::assertSame('pending', $manager->status($receipt->statusToken, $now->modify('+999999 microseconds'))->state);
        $metadata = $this->database->fetchOne("SELECT authority_snapshot::text FROM administrative_audit WHERE action='privacy.requested'");
        self::assertIsString($metadata);
        self::assertEquals(['kind' => 'correction', 'state' => 'pending', 'account_version' => 1, 'authority_expires_at' => '2026-10-12T10:00:01.123456+00:00', 'request_version' => 1, 'blockers' => []], json_decode($metadata, true, flags: JSON_THROW_ON_ERROR));
        $payload = $this->database->fetchOne('SELECT payload::text FROM outbox_messages');
        self::assertIsString($payload);
        self::assertEquals(['kind' => 'correction', 'account_id' => self::ACCOUNT, 'request_id' => self::REQUEST], json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['effect_key' => 'privacy:00000000-0000-0000-0000-000000000070:requested', 'aggregate_version' => 1, 'payload_version' => 1], $this->database->fetchAssociative('SELECT effect_key,aggregate_version,payload_version FROM outbox_messages'));
        $this->expectException(\InvalidArgumentException::class);
        $manager->status($receipt->statusToken, $now->modify('+1 second'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('conflictingRequests')]
    public function testRequestIdentityCannotBeReusedForAnotherOwnerOrKind(string $owner, string $kind): void
    {
        $store = new \App\Infrastructure\Identity\DbalIdentityStore($this->database);
        $now = new \DateTimeImmutable('2026-10-12Z');
        $store->register(self::ACCOUNT, 'private@example.test', 'hash', 'test-terms', $now);
        $store->register('00000000-0000-0000-0000-000000000002', 'other@example.test', 'hash', 'test-terms', $now);
        self::assertNotNull($this->manager(self::ACCOUNT)->request(self::REQUEST, 'access_export', $now));
        $this->expectException(\DomainException::class);
        $this->manager($owner)->request(self::REQUEST, $kind, $now);
    }

    /** @return iterable<string,array{string,string}> */
    public static function conflictingRequests(): iterable
    {
        yield 'other owner' => ['00000000-0000-0000-0000-000000000002', 'access_export'];
        yield 'other kind' => [self::ACCOUNT, 'correction'];
    }

    public function testFailedWithdrawalRollsBackEarlierWithdrawalAndAccountFence(): void
    {
        $fixture = file_get_contents(dirname(__DIR__, 2).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE checkouts SET state='completed',completed_at='2026-10-12Z'");
        $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,seller_id,price_cents) VALUES('00000000-0000-0000-0000-000000000007','00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000001',5000)");
        try {
            $this->manager(self::ACCOUNT)->request(self::REQUEST, 'deletion', new \DateTimeImmutable('2026-10-12Z'));
            self::fail('A protected paid listing must refuse withdrawal.');
        } catch (\DomainException) {
            self::assertSame('draft', $this->database->fetchOne("SELECT state FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000007'"));
            self::assertSame('published', $this->database->fetchOne("SELECT state FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000009'"));
            self::assertSame('active', $this->database->fetchOne('SELECT lifecycle_state FROM accounts WHERE id=?', [self::ACCOUNT]));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
            self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
            self::assertFalse($this->database->isTransactionActive());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('concurrentRequests')]
    public function testConcurrentIntakeRechecksRequestIdentityAfterAccountLock(string $owner, string $kind, string $expected): void
    {
        $now = new \DateTimeImmutable('2026-10-12T10:00:00Z');
        $store = new \App\Infrastructure\Identity\DbalIdentityStore($this->database);
        if ('deleted' === $expected) {
            $fixture = file_get_contents(dirname(__DIR__, 2).'/Fixtures/marketplace.sql');
            self::assertIsString($fixture);
            $this->database->executeStatement($fixture);
        } else {
            $store->register(self::ACCOUNT, 'private@example.test', 'hash', 'test-terms', $now);
            $store->register('00000000-0000-0000-0000-000000000002', 'other@example.test', 'hash', 'test-terms', $now);
        }
        $code = <<<'WORKER'
require 'vendor/autoload.php';
(new \Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
foreach (['DATABASE_URL', 'APP_SECRET', 'PRIVACY_PROGRESS_LIFETIME_SECONDS'] as $key) {
    $_SERVER[$key] = $_ENV[$key] = getenv($key);
}
$kernel = new \App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$database = $container->get(\Doctrine\DBAL\Connection::class);
$database->executeQuery("SELECT set_config('application_name',?,false)",[getenv('PRIVACY_TEST_NAME')]);
$storage = $container->get('security.token_storage');
$storage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new \App\Infrastructure\Identity\SecurityUser($argv[1], 'private@example.test', 'hash', ['runner'], 1), 'main', ['ROLE_USER']));
try {
    $receipt = $container->get(\App\Application\Privacy\ManagePrivacyRequests::class)->request('00000000-0000-0000-0000-000000000070', $argv[2], new \DateTimeImmutable('2026-10-12T10:00:00Z'));
    echo 'deletion' === $argv[2] ? $receipt?->request->state : $receipt?->statusToken;
} catch (\DomainException $error) { fwrite(STDERR, $error->getMessage()); echo 'denied'; }
WORKER;
        $name = 'privacy_'.bin2hex(random_bytes(6));
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);
        $process = new \Symfony\Component\Process\Process(['php', '-r', $code, $owner, $kind], dirname(__DIR__, 3), ['DATABASE_URL' => $url, 'APP_SECRET' => 'test-only-privacy-secret', 'PRIVACY_PROGRESS_LIFETIME_SECONDS' => '60', 'PRIVACY_TEST_NAME' => $name]);
        $process->setTimeout(15);
        $this->database->beginTransaction();
        if ('deleted' === $expected) {
            $this->database->fetchOne("SELECT id FROM races WHERE id='00000000-0000-0000-0000-000000000004' FOR UPDATE");
        } else {
            $this->database->fetchOne('SELECT id FROM accounts WHERE id=? FOR UPDATE', [$owner]);
        }
        try {
            $process->start();
            $deadline = microtime(true) + 10;
            do {
                $this->database->executeQuery('SELECT pg_stat_clear_snapshot()');
                $blocked = $this->database->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$name]);
                if (1 === $blocked) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(1, $blocked, 'Contender must reach the account lock before the request is committed. '.$process->getErrorOutput().$process->getOutput());
            if ('deleted' === $expected) {
                self::assertSame($owner, $this->database->fetchOne('SELECT id::text FROM accounts WHERE id=? FOR UPDATE NOWAIT', [$owner]), 'Deletion must wait on races before locking the account.');
                $output = 'blocked';
            } else {
                $receipt = $this->manager(self::ACCOUNT)->request(self::REQUEST, 'access_export', $now);
                self::assertNotNull($receipt);
                $output = 'replayed' === $expected ? $receipt->statusToken : 'denied';
            }
            $this->database->commit();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertSame($output, $process->getOutput());
            self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='privacy.requested'"));
            self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='privacy.requested'"));
        } finally {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
            $process->stop();
        }
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function concurrentRequests(): iterable
    {
        yield 'deletion race before account' => [self::ACCOUNT, 'deletion', 'deleted'];
        yield 'same request' => [self::ACCOUNT, 'access_export', 'replayed'];
        yield 'different kind' => [self::ACCOUNT, 'correction', 'denied'];
        yield 'different owner' => ['00000000-0000-0000-0000-000000000002', 'access_export', 'denied'];
    }

    public function testDeletionWaitsForRaceBeforeHoldingAccountLock(): void
    {
        $fixture = file_get_contents(dirname(__DIR__, 2).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $code = <<<'WORKER'
require 'vendor/autoload.php';
$database = \Doctrine\DBAL\DriverManager::getConnection(json_decode(getenv('PRIVACY_TEST_PARAMS'), true, 512, JSON_THROW_ON_ERROR));
$database->beginTransaction();
$database->fetchOne("SELECT id FROM races WHERE id='00000000-0000-0000-0000-000000000004' FOR UPDATE");
echo "READY\n";
$deadline = microtime(true) + 10;
do {
    $database->executeQuery('SELECT pg_stat_clear_snapshot()');
    $waiting = $database->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock'", [(int) getenv('PRIVACY_TEST_PID')]);
    if (1 === $waiting) { break; }
    usleep(20000);
} while (microtime(true) < $deadline);
try {
    if (1 !== $waiting) { throw new \RuntimeException('Request did not wait on race.'); }
    $database->fetchOne("SELECT id FROM accounts WHERE id='00000000-0000-0000-0000-000000000001' FOR UPDATE NOWAIT");
    echo 'RACE_BEFORE_ACCOUNT';
} catch (\Throwable $error) { echo $error->getMessage(); }
finally { $database->rollBack(); }
WORKER;
        $backendPid = $this->database->fetchOne('SELECT pg_backend_pid()');
        self::assertIsInt($backendPid);
        $process = new \Symfony\Component\Process\Process(['php', '-r', $code], dirname(__DIR__, 3), ['PRIVACY_TEST_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR), 'PRIVACY_TEST_PID' => (string) $backendPid]);
        $process->setTimeout(15);
        try {
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY')));
            $receipt = $this->manager(self::ACCOUNT)->request(self::REQUEST, 'deletion', new \DateTimeImmutable('2026-10-12Z'));
            self::assertNotNull($receipt);
            self::assertSame('blocked', $receipt->request->state);
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertSame("READY\nRACE_BEFORE_ACCOUNT", $process->getOutput());
        } finally {
            $process->stop();
        }
    }

    public function testContainerProvidesFailClosedPrivacyIntake(): void
    {
        $manager = self::getContainer()->get(\App\Application\Privacy\ManagePrivacyRequests::class);
        self::assertNull($manager->request(self::REQUEST, 'access_export', new \DateTimeImmutable('2026-10-12Z')));
    }

    private function manager(?string $accountId, ?int $lifetime = 60): \App\Application\Privacy\ManagePrivacyRequests
    {
        $tokens = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        if (null !== $accountId) {
            $tokens->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new \App\Infrastructure\Identity\SecurityUser($accountId, 'private@example.test', 'hash', ['runner'], 1), 'main', ['ROLE_USER']));
        }
        $audit = new \App\Infrastructure\Operations\DbalAuditWriter($this->database);
        $outbox = new \App\Infrastructure\Operations\DbalOutboxWriter($this->database);
        $availability = new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), $outbox);

        return new \App\Application\Privacy\ManagePrivacyRequests(new \App\Infrastructure\Identity\SecurityCurrentIdentity($tokens), new \App\Infrastructure\Privacy\DbalPrivacyRequestStore($this->database), new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Selling\DbalListingStore($this->database, $availability, 'test-owned-provider-scope', $audit, $outbox), new \App\Infrastructure\Finance\DbalCheckoutCancellationRequests($this->database, $outbox), $audit, $outbox, new \App\Infrastructure\Privacy\HmacPrivacyRequestTokens('test-only-privacy-secret'), $lifetime);
    }
}
