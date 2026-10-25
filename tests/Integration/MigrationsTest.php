<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class MigrationsTest extends KernelTestCase
{
    private Connection $admin;
    private Connection $database;
    private string $databaseName;
    private string $databaseUrl;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $this->admin = $connection;
        $this->databaseName = 'migration_test_'.bin2hex(random_bytes(6));
        $this->admin->executeStatement('CREATE DATABASE '.$this->databaseName);
        $parameters = $this->admin->getParams();
        $parameters['dbname'] = $this->databaseName;
        $this->database = DriverManager::getConnection($parameters);
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($url);
        $this->databaseUrl = (string) preg_replace('~/[^/?]+(?=\\?|$)~', '/'.$this->databaseName, $url);
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->admin->executeStatement('DROP DATABASE '.$this->databaseName.' WITH (FORCE)');
        parent::tearDown();
    }

    public function testFullSchemaCanBeAppliedRolledBackAndReplayed(): void
    {
        $this->migrate('latest');
        self::assertSame(41, $this->integerValue("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name NOT IN ('doctrine_migration_versions','messenger_messages')"));
        $this->migrate('first');
        self::assertSame(0, $this->integerValue("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name NOT IN ('doctrine_migration_versions','messenger_messages')"));
        $this->migrate('latest');
        self::assertSame(41, $this->integerValue("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name NOT IN ('doctrine_migration_versions','messenger_messages')"));
    }

    public function testCommittedCheckoutTermsCannotBeRepriced(): void
    {
        $this->seed();
        $this->assertRejected('UPDATE checkout_items SET price_cents=11000, buyer_total_cents=11500, seller_net_cents=10500', '23514');
    }

    public function testFinancialIntentIsImmutable(): void
    {
        $this->seed();
        $this->assertRejected('UPDATE financial_operations SET expected_amount_cents=11500', '23514');
    }

    public function testPrivateSnapshotCanBeRedactedWithoutChangingFinancialFacts(): void
    {
        $this->seed();
        $this->database->executeStatement("UPDATE checkout_items SET buyer_name=NULL, buyer_email=NULL, registration_fields=NULL, private_redacted_at='2026-10-08Z'");
        self::assertSame(10500, $this->integerValue('SELECT buyer_total_cents FROM checkout_items'));
        self::assertNull($this->database->fetchOne('SELECT buyer_email FROM checkout_items'));
        $this->assertRejected("UPDATE checkout_items SET buyer_name='Restored', buyer_email='restored@example.test', registration_fields='{}', private_redacted_at=NULL", '23514');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidChanges')]
    public function testDatabaseRejectsInvalidBusinessRecords(string $sql, string $state): void
    {
        $this->seed();
        $this->assertRejected($sql, $state);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidChanges(): iterable
    {
        yield 'participation requires organization' => ["UPDATE races SET participation='participating'", '23514'];
        yield 'nonparticipating bounds forbidden' => ['UPDATE races SET min_price_cents=100', '23514'];
        yield 'reviewed proof requires exact version' => ['UPDATE ownership_proofs SET reviewed_version=NULL', '23514'];
        yield 'positive distance' => ['UPDATE races SET distance_metres=0', '23514'];
        yield 'coordinate pair' => ['UPDATE races SET latitude=48', '23514'];
        yield 'schema version pair' => ['UPDATE races SET registration_schema_version=1', '23514'];
        yield 'json schema must be object' => ["UPDATE races SET registration_schema_version=1, registration_schema='[]'", '23514'];
        yield 'race identity preserved' => ["DELETE FROM races WHERE id='00000000-0000-0000-0000-000000000004'", '23503'];
        yield 'listing race context' => ["UPDATE resale_listings SET state='withdrawn', withdrawn_at='2026-01-02Z', race_id='00000000-0000-0000-0000-000000000005'", '23503'];
        yield 'unresolved financial attempt cannot be duplicated' => ["INSERT INTO financial_operation_attempts(id,operation_id,attempt_no,provider,provider_scope,idempotency_key,state,prepared_at) VALUES ('00000000-0000-0000-0000-000000000016','00000000-0000-0000-0000-000000000013',2,'stripe','test:platform','key-2','prepared','2026-01-01Z')", '23505'];
        yield 'private snapshot may not be edited' => ["UPDATE checkout_items SET buyer_email='other@example.test'", '23514'];
        yield 'history cannot be deleted' => ['DELETE FROM checkout_items', '23514'];
        yield 'newsletter requires unsubscribe time' => ["INSERT INTO newsletter_subscriptions(id,email,email_normalized,subscribed,join_at) VALUES ('00000000-0000-0000-0000-000000000017','n@example.test','n@example.test',false,'2026-01-01Z')", '23514'];
    }

    public function testBibNumberAndMissingOrganizerArePreserved(): void
    {
        $this->seed();
        self::assertSame('00123', $this->database->fetchOne('SELECT bib_number FROM bib_entitlements'));
        self::assertSame(2, $this->integerValue("SELECT count(*) FROM races WHERE publication_state='published' AND organization_id IS NULL"));
    }

    public function testTerminalAttemptAllowsDistinctRetryAndScopedProviderEvents(): void
    {
        $this->seed();
        $this->database->executeStatement("UPDATE financial_operation_attempts SET state='failed', resolved_at='2026-01-02Z'");
        $this->database->executeStatement("INSERT INTO financial_operation_attempts(id,operation_id,attempt_no,provider,provider_scope,idempotency_key,state,prepared_at) VALUES ('00000000-0000-0000-0000-000000000016','00000000-0000-0000-0000-000000000013',2,'stripe','test:platform','key-2','prepared','2026-01-02Z')");
        self::assertSame(2, $this->integerValue('SELECT count(*) FROM financial_operation_attempts'));
        $this->database->executeStatement("INSERT INTO provider_event_inbox(id,provider,provider_scope,event_ref,received_at,authenticated_at,payload_version,protected_payload,processing_state) VALUES ('00000000-0000-0000-0000-000000000018','stripe','test:platform','evt_1','2026-01-01Z','2026-01-01Z',1,'{}','pending'),('00000000-0000-0000-0000-000000000019','stripe','live:platform','evt_1','2026-01-01Z','2026-01-01Z',1,'{}','pending')");
        self::assertSame(2, $this->integerValue('SELECT count(*) FROM provider_event_inbox'));
    }

    public function testListingCannotBePublishedWithUnapprovedProof(): void
    {
        $this->seed();
        $this->database->executeStatement("UPDATE ownership_proofs SET decision='rejected'");
        $this->assertRejected('UPDATE resale_listings SET price_cents=11000', '23514');
    }

    public function testConcurrentCheckoutProtectionCannotBeTakenTwice(): void
    {
        $this->seed();
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released', released_at='2026-01-02Z'");
        $this->database->executeStatement("INSERT INTO checkouts(id,buyer_id,state,authorized_at,initial_deadline,expected_total_cents,currency) SELECT '00000000-0000-0000-0000-000000000020',buyer_id,state,authorized_at,initial_deadline,expected_total_cents,currency FROM checkouts");
        $competitor = DriverManager::getConnection($this->database->getParams());
        $competitor->executeStatement("SET lock_timeout='100ms'");
        $this->database->executeStatement("INSERT INTO checkouts(id,buyer_id,state,authorized_at,initial_deadline,expected_total_cents,currency) SELECT '00000000-0000-0000-0000-000000000023',buyer_id,state,authorized_at,initial_deadline,expected_total_cents,currency FROM checkouts WHERE id='00000000-0000-0000-0000-000000000011'");
        $this->database->beginTransaction();
        try {
            $sql = "INSERT INTO checkout_items(id,checkout_id,race_id,entitlement_id,listing_id,seller_id,availability_event_id,offer_version,price_cents,buyer_fee_cents,seller_margin_cents,buyer_total_cents,seller_net_cents,currency,fee_policy_version,buyer_name,buyer_email,registration_fields,participation_snapshot,acknowledgment_text_version,acknowledgment_accepted_at,initial_expires_at) SELECT '00000000-0000-0000-0000-000000000021','00000000-0000-0000-0000-000000000020',race_id,entitlement_id,listing_id,seller_id,availability_event_id,offer_version,price_cents,buyer_fee_cents,seller_margin_cents,buyer_total_cents,seller_net_cents,currency,fee_policy_version,buyer_name,buyer_email,registration_fields,participation_snapshot,acknowledgment_text_version,acknowledgment_accepted_at,initial_expires_at FROM checkout_items WHERE id='00000000-0000-0000-0000-000000000012'";
            $this->database->executeStatement($sql);
            $sql = str_replace(['00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000020'], ['00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023'], $sql);
            try {
                $competitor->executeStatement($sql);
                self::fail('Concurrent protection was accepted.');
            } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
            $this->database->commit();
            try {
                $competitor->executeStatement($sql);
                self::fail('Duplicate protected entitlement was accepted.');
            } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
                self::assertSame('23505', $exception->getSQLState());
            }
        } finally {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
            $competitor->close();
        }
    }

    public function testReleasedCheckoutProtectionCannotBeReactivated(): void
    {
        $this->seed();
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released', released_at='2026-01-02Z'");
        $this->assertRejected("UPDATE checkout_items SET protection_state='protected', released_at=NULL", '23514');
    }

    private function seed(): void
    {
        $this->migrate('latest');
        $sql = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($sql);
        foreach (explode(";\n", $sql) as $statement) {
            if ('' !== trim($statement)) {
                $this->database->executeStatement(trim($statement));
            }
        }
    }

    private function assertRejected(string $sql, string $state): void
    {
        try {
            $this->database->executeStatement($sql);
        } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
            self::assertSame($state, $exception->getSQLState());

            return;
        }
        self::fail('Database accepted a forbidden change.');
    }

    private function integerValue(string $sql): int
    {
        $value = $this->database->fetchOne($sql);
        self::assertTrue(is_int($value) || (is_string($value) && ctype_digit($value)));

        return (int) $value;
    }

    private function migrate(string $version): void
    {
        $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', $version, '--no-interaction', '--allow-no-migration'], dirname(__DIR__, 2), ['DATABASE_URL' => $this->databaseUrl, 'APP_ENV' => 'test']);
        $process->mustRun();
    }
}
