<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Infrastructure\Identity\DbalProfileStore;
use App\Tests\Support\IsolatedWebTestCase;

final class ProfileLifecycleTest extends IsolatedWebTestCase
{
    public function testPrivateProfileUpdatesAreVersionedAndDoNotChangeLoginEmail(): void
    {
        $store = new \App\Infrastructure\Identity\DbalIdentityStore($this->database);
        $store->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-08Z'));
        $profiles = new DbalProfileStore($this->database);
        $profile = $profiles->privateProfile('00000000-0000-0000-0000-000000000001');
        self::assertNotNull($profile);
        self::assertSame('runner@example.test', $profile->email);
        self::assertNull($profile->displayName);
        self::assertFalse($profile->optionalEmailEnabled);
        self::assertFalse($profile->optionalInAppEnabled);
        self::assertSame(1, $profile->version);
        self::assertTrue($profiles->update('00000000-0000-0000-0000-000000000001', 'Runner', true, true, 1));
        self::assertFalse($profiles->update('00000000-0000-0000-0000-000000000001', 'Stale', false, false, 1));
        self::assertSame('Runner', $profiles->privateProfile('00000000-0000-0000-0000-000000000001')?->displayName);
        self::assertSame('runner@example.test', $profiles->privateProfile('00000000-0000-0000-0000-000000000001')->email);
        self::assertNull($profiles->privateProfile('00000000-0000-0000-0000-000000000099'));
        $public = $profiles->publicProfile('00000000-0000-0000-0000-000000000001');
        self::assertNotNull($public);
        self::assertSame('Runner', $public->displayName);
        self::assertSame([], $public->bibs);
        self::assertSame(['displayName', 'bibs'], array_keys(get_object_vars($public)));
    }

    public function testMissingProfileCannotBeReportedAsUpdated(): void
    {
        $this->database->insert('accounts', ['id' => '00000000-0000-0000-0000-000000000002', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09T12:00:00Z']);
        self::assertFalse((new DbalProfileStore($this->database))->update('00000000-0000-0000-0000-000000000002', 'Runner', true, true, 1));
        self::assertSame(1, $this->database->fetchOne("SELECT version FROM accounts WHERE id='00000000-0000-0000-0000-000000000002'"));
    }

    public function testSelfServiceProfileAndConsentAuditAreAtomicAndScoped(): void
    {
        $store = new \App\Infrastructure\Identity\DbalIdentityStore($this->database);
        $store->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $anonymous = $this->manager(null);
        self::assertNull($anonymous->current());
        self::assertFalse($anonymous->update('Untrusted', true, true, 1));
        $manager = $this->manager('00000000-0000-0000-0000-000000000001');
        self::assertSame('runner@example.test', $manager->current()?->email);
        self::assertTrue($manager->update('  Runner  ', true, true, 1));
        self::assertSame('Runner', $manager->current()->displayName);
        self::assertFalse($manager->update('Stale write', false, false, 1));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='account.profile_updated'"));
        self::assertSame('account.profile_updated', $this->database->fetchOne("SELECT action FROM administrative_audit WHERE action='account.profile_updated'"));
        $authority = $this->database->fetchOne("SELECT authority_snapshot FROM administrative_audit WHERE action='account.profile_updated'");
        self::assertIsString($authority);
        self::assertEquals(['scope' => 'self_service', 'optional_email_enabled' => true, 'optional_in_app_enabled' => true], json_decode($authority, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('Runner', $manager->publicProfile('00000000-0000-0000-0000-000000000001')?->displayName);
        self::assertNull($manager->publicProfile('00000000-0000-0000-0000-000000000099'));
        self::assertNull($manager->publicProfile('not-a-uuid'));
        self::assertTrue($manager->update('   ', false, false, 2));
        self::assertNull($manager->current()->displayName);
        self::assertFalse($manager->current()->optionalEmailEnabled);
        self::assertTrue($manager->update(null, false, false, 3));
    }

    public function testFailedAuditWriteRollsBackProfileAndVersion(): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $this->database->executeStatement("CREATE FUNCTION fail_test_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Simulated audit storage failure'; END; $$");
        $this->database->executeStatement('CREATE TRIGGER test_audit_failure BEFORE INSERT ON administrative_audit FOR EACH ROW EXECUTE FUNCTION fail_test_audit()');
        $manager = $this->manager('00000000-0000-0000-0000-000000000001');
        try {
            $manager->update('Should roll back', true, true, 1);
            self::fail('Expected audit storage failure');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            self::assertStringContainsString('Simulated audit storage failure', $error->getMessage());
        }
        self::assertNull($manager->current()?->displayName);
        self::assertFalse($manager->current()?->optionalEmailEnabled);
        self::assertSame(1, $manager->current()->version);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        $this->database->executeStatement('DROP TRIGGER test_audit_failure ON administrative_audit');
        self::assertTrue($manager->update('Retry succeeds', true, true, 1));
        self::assertSame(2, $manager->current()->version);
        self::assertSame(2, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='notification.preference_granted'"));
    }

    public function testPublicProfileOnlyDisplaysPublicRaceBibs(): void
    {
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->insert('account_private_profiles', ['account_id' => '00000000-0000-0000-0000-000000000001', 'email' => 'private@example.test', 'email_normalized' => 'private@example.test', 'display_name' => 'Runner']);
        $profiles = new DbalProfileStore($this->database);
        $public = $profiles->publicProfile('00000000-0000-0000-0000-000000000001');
        self::assertNotNull($public);
        self::assertSame([['race_id' => '00000000-0000-0000-0000-000000000004', 'race_name' => 'Paris Marathon', 'bib_number' => '00123']], $public->bibs);
        $this->database->executeStatement("UPDATE races SET publication_state='draft' WHERE id='00000000-0000-0000-0000-000000000004'");
        self::assertSame([], $profiles->publicProfile('00000000-0000-0000-0000-000000000001')?->bibs);
    }

    public function testUnapprovedOptionalGrantCannotChangeProfileOrVersion(): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $manager = $this->manager('00000000-0000-0000-0000-000000000001', null, null);
        try {
            $manager->update('Must remain private', true, false, 1);
            self::fail('An optional grant needs an explicitly approved policy version.');
        } catch (\DomainException $error) {
            self::assertSame('Optional notification policy is not configured.', $error->getMessage());
        }
        self::assertNull($manager->current()?->displayName);
        self::assertFalse($manager->current()?->optionalEmailEnabled);
        self::assertSame(1, $manager->current()->version);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testChangedChannelsHaveSeparateVersionedEvidenceWithoutPersonalPayloads(): void
    {
        $identity = new \App\Infrastructure\Identity\DbalIdentityStore($this->database);
        $identity->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $identity->register('00000000-0000-0000-0000-000000000002', 'other@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $manager = $this->manager('00000000-0000-0000-0000-000000000001');
        self::assertTrue($manager->update('Runner', true, false, 1));
        $rows = $this->database->fetchAllAssociative("SELECT actor_account_id,subject_id,action,authority_snapshot FROM administrative_audit WHERE action='notification.preference_granted'");
        self::assertCount(1, $rows);
        self::assertSame('00000000-0000-0000-0000-000000000001', $rows[0]['actor_account_id']);
        self::assertSame($rows[0]['actor_account_id'], $rows[0]['subject_id']);
        self::assertIsString($rows[0]['authority_snapshot']);
        self::assertEquals(['scope' => 'self_service', 'source' => 'profile', 'purpose' => 'optional_notification_email', 'policy_version' => 'test-approved-email-v1', 'previous_enabled' => false, 'enabled' => true, 'account_version' => 2], json_decode($rows[0]['authority_snapshot'], true, 512, JSON_THROW_ON_ERROR));
        self::assertFalse($manager->update('Stale', false, true, 1));
        self::assertTrue($manager->update('Runner renamed', true, false, 2));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action LIKE 'notification.preference_%'"));
        self::assertTrue($manager->update(null, true, true, 3));
        $inApp = $this->database->fetchOne("SELECT authority_snapshot->>'policy_version' FROM administrative_audit WHERE authority_snapshot->>'purpose'='optional_notification_in_app'");
        self::assertSame('test-approved-in-app-v2', $inApp);
        self::assertFalse((new DbalProfileStore($this->database))->privateProfile('00000000-0000-0000-0000-000000000002')?->optionalEmailEnabled);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM race_waitlist_memberships'));
    }

    public function testWithdrawalRemainsAvailableWithoutPolicyAndCannotDuplicateEvidence(): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $this->database->executeStatement("UPDATE accounts SET optional_email_enabled=true,optional_in_app_enabled=true WHERE id='00000000-0000-0000-0000-000000000001'");
        $manager = $this->manager('00000000-0000-0000-0000-000000000001', null, '  ');
        self::assertTrue($manager->update(null, false, false, 1));
        self::assertFalse($manager->update(null, false, false, 1));
        self::assertTrue($manager->update('Name only', false, false, 2));
        $evidence = $this->database->fetchAllAssociative("SELECT authority_snapshot,occurred_at,actor_account_id FROM administrative_audit WHERE action='notification.preference_withdrawn'");
        self::assertCount(2, $evidence);
        foreach ($evidence as $event) {
            self::assertIsString($event['authority_snapshot']);
            $payload = json_decode($event['authority_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertNull($payload['policy_version']);
            self::assertTrue($payload['previous_enabled']);
            self::assertFalse($payload['enabled']);
            self::assertSame(2, $payload['account_version']);
            self::assertNotEmpty($event['occurred_at']);
        }
        self::assertFalse($manager->current()?->optionalEmailEnabled);
        self::assertFalse($manager->current()->optionalInAppEnabled);
        self::assertFalse($this->manager('00000000-0000-0000-0000-000000000099', null, null)->update('Missing', true, true, 1));
        self::assertFalse($manager->update('Stale', true, true, 1));
        self::assertSame(2, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action LIKE 'notification.preference_%'"));
    }

    public function testAnUnapprovedSiblingChannelRejectsTheWholeMixedChange(): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $manager = $this->manager('00000000-0000-0000-0000-000000000001', 'test-approved-email-v1', '  ');
        try {
            $manager->update('Mixed', true, true, 1);
            self::fail('Unapproved in-app grant must fail closed as well.');
        } catch (\DomainException) {
            self::assertSame(1, $manager->current()?->version);
        }
        self::assertNull($manager->current()->displayName);
        self::assertFalse($manager->current()->optionalEmailEnabled);
        self::assertFalse($manager->current()->optionalInAppEnabled);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testPublicProfileRejectsUuidPrefixesSuffixesAndTrailingNewlines(): void
    {
        foreach (['x00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001x', "00000000-0000-0000-0000-000000000001\n"] as $id) {
            self::assertNull($this->manager(null)->publicProfile($id));
        }
    }

    private function manager(?string $id, ?string $emailPolicyVersion = ' test-approved-email-v1 ', ?string $inAppPolicyVersion = ' test-approved-in-app-v2 '): \App\Application\Identity\ManageProfile
    {
        $tokens = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        if (null !== $id) {
            $tokens->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new \App\Infrastructure\Identity\SecurityUser($id, 'runner@example.test', 'hash', ['runner'], 1), 'main', ['ROLE_USER']));
        }
        $effects = new \App\Infrastructure\Identity\OutboxIdentityEffects(new \App\Infrastructure\Operations\DbalOutboxWriter($this->database), new \Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory([\App\Infrastructure\Identity\SecurityUser::class => ['algorithm' => 'argon2id']]), new \App\Infrastructure\Operations\AesCredentialTokenProtector('credential-token-secret-for-tests-0123456789'));

        return new \App\Application\Identity\ManageProfile(new \App\Infrastructure\Identity\SecurityCurrentIdentity($tokens), new DbalProfileStore($this->database), $effects, new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database), $emailPolicyVersion, $inAppPolicyVersion);
    }
}
