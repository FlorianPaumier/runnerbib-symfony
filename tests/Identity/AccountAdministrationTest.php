<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Domain\Identity\AccountLifecycle;
use App\Infrastructure\Identity\DbalAccountLifecycleStore;
use App\Infrastructure\Identity\DbalIdentityStore;
use App\Infrastructure\Operations\DbalAuditWriter;
use App\Tests\Support\IsolatedWebTestCase;

final class AccountAdministrationTest extends IsolatedWebTestCase
{
    public function testOnlyCurrentAuthorizedAdminCanSuspendAndAuditWithoutChangingFinancialFacts(): void
    {
        $now = new \DateTimeImmutable('2026-10-09T12:00:00Z');
        $identities = new DbalIdentityStore($this->database);
        $identities->register('00000000-0000-0000-0000-000000000001', 'admin@example.test', 'hash', 'v1', $now);
        $identities->register('00000000-0000-0000-0000-000000000002', 'runner@example.test', 'hash', 'v1', $now);
        $identities->issueCredential('00000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000002', 'password_reset', 'credential-hash', $now, $now->modify('+1 hour'));
        $store = new DbalAccountLifecycleStore($this->database, new DbalAuditWriter($this->database));
        self::assertFalse($store->change('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', new AccountLifecycle('suspended', 'Safety review'), '00000000-0000-0000-0000-000000000003', $now));
        self::assertNotNull($identities->findActive('runner@example.test'));
        $this->database->executeStatement("UPDATE accounts SET roles='[\"admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
        self::assertTrue($store->change('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', new AccountLifecycle('suspended', 'Safety review'), '00000000-0000-0000-0000-000000000003', $now));
        self::assertNull($identities->findActive('runner@example.test'));
        self::assertSame(2, $this->database->fetchOne("SELECT version FROM accounts WHERE id='00000000-0000-0000-0000-000000000002'"));
        self::assertSame('hash', $this->database->fetchOne("SELECT password_hash FROM accounts WHERE id='00000000-0000-0000-0000-000000000002'"));
        self::assertSame('runner@example.test', $this->database->fetchOne("SELECT email FROM account_private_profiles WHERE account_id='00000000-0000-0000-0000-000000000002'"));
        self::assertNotNull($this->database->fetchOne('SELECT consumed_at FROM account_credentials'));
        self::assertSame('account.suspended', $this->database->fetchOne('SELECT action FROM administrative_audit'));
        self::assertSame('Safety review', $this->database->fetchOne('SELECT reason FROM administrative_audit'));
        self::assertFalse($store->change('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', new AccountLifecycle('suspended', 'Replay'), '00000000-0000-0000-0000-000000000005', $now));
        self::assertTrue($store->change('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', new AccountLifecycle('active', 'Review completed'), '00000000-0000-0000-0000-000000000006', $now));
        self::assertNotNull($identities->findActive('runner@example.test'));
        self::assertSame(3, $identities->findActive('runner@example.test')['version']);
        self::assertFalse($identities->consumeCredential('credential-hash', 'password_reset', $now, 'unexpected-new-hash'));
        self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testMissingOrSuspendedAuthorityAndAnonymizedTargetsAreDenied(): void
    {
        $now = new \DateTimeImmutable('2026-10-09T12:00:00Z');
        $identities = new DbalIdentityStore($this->database);
        $identities->register('00000000-0000-0000-0000-000000000001', 'admin@example.test', 'hash', 'v1', $now);
        $identities->register('00000000-0000-0000-0000-000000000002', 'runner@example.test', 'hash', 'v1', $now);
        $this->database->executeStatement("UPDATE accounts SET roles='[\"admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
        $store = new DbalAccountLifecycleStore($this->database, new DbalAuditWriter($this->database));
        $change = new AccountLifecycle('suspended', 'Review');
        self::assertFalse($store->change('00000000-0000-0000-0000-000000000099', '00000000-0000-0000-0000-000000000002', $change, '00000000-0000-0000-0000-000000000003', $now));
        self::assertFalse($store->change('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000099', $change, '00000000-0000-0000-0000-000000000003', $now));
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='suspended' WHERE id='00000000-0000-0000-0000-000000000001'");
        self::assertFalse($store->change('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', $change, '00000000-0000-0000-0000-000000000003', $now));
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='active',roles='[123]' WHERE id='00000000-0000-0000-0000-000000000001'");
        self::assertFalse($store->change('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', $change, '00000000-0000-0000-0000-000000000003', $now));
        $this->database->executeStatement("UPDATE accounts SET roles='[\"admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
        // A synthetic tombstone exercises refusal only; this is not the application's erasure workflow.
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='anonymized',anonymized_at='2026-10-09Z' WHERE id='00000000-0000-0000-0000-000000000002'");
        self::assertFalse($store->change('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', new AccountLifecycle('active', 'Cannot restore'), '00000000-0000-0000-0000-000000000003', $now));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testFailedAuditRollsBackSuspensionAndCredentialRevocation(): void
    {
        $now = new \DateTimeImmutable('2026-10-09T12:00:00Z');
        $identities = new DbalIdentityStore($this->database);
        $identities->register('00000000-0000-0000-0000-000000000001', 'admin@example.test', 'hash', 'v1', $now);
        $identities->register('00000000-0000-0000-0000-000000000002', 'runner@example.test', 'hash', 'v1', $now);
        $identities->issueCredential('00000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000002', 'password_reset', 'token-hash', $now, $now->modify('+1 hour'));
        $this->database->executeStatement("UPDATE accounts SET roles='[\"admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
        $this->database->executeStatement("CREATE FUNCTION fail_test_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Simulated audit storage failure'; END; $$");
        $this->database->executeStatement('CREATE TRIGGER test_audit_failure BEFORE INSERT ON administrative_audit FOR EACH ROW EXECUTE FUNCTION fail_test_audit()');
        $store = new DbalAccountLifecycleStore($this->database, new DbalAuditWriter($this->database));
        try {
            $store->change('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', new AccountLifecycle('suspended', 'Review'), '00000000-0000-0000-0000-000000000003', $now);
            self::fail('Expected audit storage failure');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            self::assertStringContainsString('Simulated audit storage failure', $error->getMessage());
        }
        self::assertNotNull($identities->findActive('runner@example.test'));
        self::assertSame(1, $identities->findActive('runner@example.test')['version']);
        self::assertNull($this->database->fetchOne('SELECT consumed_at FROM account_credentials'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testAnonymousApplicationCallerCannotChangeAccountState(): void
    {
        self::bootKernel();
        $accounts = self::getContainer()->get(\App\Application\Identity\AdministerAccounts::class);
        self::assertFalse($accounts->change('00000000-0000-0000-0000-000000000001', 'suspended', 'Untrusted request'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }
}
