<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class OperationsCommandsTest extends IsolatedWebTestCase
{
    public function testSellerReconciliationCommandReportsItsResultAndRefusesInvalidLimits(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:sellers:reconcile'));
        self::assertSame(0, $tester->execute(['--limit' => '10']));
        self::assertSame("Reconciled sellers: 0, failed: 0\n", $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('between 1 and 1000', $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--limit' => '1001'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('between 1 and 1000', $tester->getErrorOutput());
    }

    public function testSellerReconciliationCommandFailsWhenTheProviderIsUnavailable(): void
    {
        $this->database->insert('accounts', ['id' => '00000000-0000-0000-0000-000000000001', 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('seller_accounts', ['id' => '00000000-0000-0000-0000-000000000050', 'seller_id' => '00000000-0000-0000-0000-000000000001', 'provider' => 'stripe', 'provider_scope' => 'unconfigured', 'connected_account_ref' => 'acct_recipient', 'readiness_state' => 'pending', 'last_reconciled_at' => '2026-01-01T00:00:00Z']);
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:sellers:reconcile'));
        self::assertSame(1, $tester->execute(['--limit' => '10']));
        self::assertSame("Reconciled sellers: 0, failed: 1\n", $tester->getDisplay());
    }

    public function testProviderEventCommandReportsItsResultAndRefusesInvalidLimits(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:provider-events:process'));
        self::assertSame(0, $tester->execute(['--limit' => '10']));
        self::assertSame("Provider events processed: 0, ignored: 0, failed: 0\n", $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('between 1 and 1000', $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--limit' => '1001'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('between 1 and 1000', $tester->getErrorOutput());
    }

    public function testProviderEventCommandIgnoresAnEventWithoutCheckout(): void
    {
        $this->database->insert('provider_event_inbox', ['id' => '00000000-0000-0000-0000-0000000000d1', 'provider' => 'stripe', 'provider_scope' => 'unconfigured', 'event_ref' => 'evt_account', 'received_at' => '2026-01-01Z', 'authenticated_at' => '2026-01-01Z', 'payload_version' => 1, 'protected_payload' => json_encode(['id' => 'evt_account', 'type' => 'account.updated', 'data' => ['object' => ['id' => 'acct_1']]], JSON_THROW_ON_ERROR), 'processing_state' => 'pending']);
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:provider-events:process'));
        self::assertSame(0, $tester->execute(['--limit' => '10']));
        self::assertSame("Provider events processed: 0, ignored: 1, failed: 0\n", $tester->getDisplay());
        self::assertSame('completed', $this->database->fetchOne('SELECT processing_state FROM provider_event_inbox'));
    }

    public function testRetentionCommandReportsWhatItDeleted(): void
    {
        $this->database->insert('accounts', ['id' => '00000000-0000-0000-0000-000000000001', 'roles' => '["runner"]', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-01-01Z']);
        $this->database->insert('account_credentials', ['id' => '00000000-0000-0000-0000-000000000002', 'account_id' => '00000000-0000-0000-0000-000000000001', 'purpose' => 'verification', 'token_hash' => 'expired-hash', 'created_at' => '2026-01-01Z', 'expires_at' => '2026-01-02Z', 'consumed_at' => '2026-01-01T12:00:00Z']);
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:retention:purge'));
        self::assertSame(0, $tester->execute([]));
        self::assertSame("Purged account_credentials: 1\n", $tester->getDisplay());
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM account_credentials'));
        self::assertSame(0, $tester->execute([]));
        self::assertSame("Nothing to purge.\n", $tester->getDisplay());
    }

    public function testSellerReconciliationCommandFailsWhenTheDatabaseIsUnavailable(): void
    {
        $this->database->executeStatement('DROP TABLE seller_accounts CASCADE');
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:sellers:reconcile'));
        self::assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame("Seller reconciliation failed; retry on the next cycle.\n", $tester->getErrorOutput());
    }

    public function testProviderEventCommandFailsWhenTheDatabaseIsUnavailable(): void
    {
        $this->database->executeStatement('DROP TABLE provider_event_inbox CASCADE');
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:provider-events:process'));
        self::assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame("Provider event processing failed; the events stay pending for the next cycle.\n", $tester->getErrorOutput());
    }

    public function testRetentionCommandFailsWithoutDeletingAnythingWhenTheDatabaseIsUnavailable(): void
    {
        $this->database->executeStatement('DROP TABLE outbox_messages');
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:retention:purge'));
        self::assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame("Retention purge failed; nothing was deleted.\n", $tester->getErrorOutput());
    }
}
