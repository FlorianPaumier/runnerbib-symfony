<?php

declare(strict_types=1);

namespace App\Tests\Integration\Availability;

use App\Application\Availability\ManageRaceWaitlist;
use App\Domain\Identity\Actor;
use App\Infrastructure\Availability\DbalWaitlistStore;
use App\Infrastructure\Operations\DbalTransactionManager;
use App\Tests\Support\IsolatedWebTestCase;

final class RaceWaitlistTest extends IsolatedWebTestCase
{
    private const ACCOUNT = '00000000-0000-0000-0000-000000000001';
    private const OTHER = '00000000-0000-0000-0000-000000000002';
    private const RACE = '00000000-0000-0000-0000-000000000010';
    private const MEMBERSHIP = '00000000-0000-0000-0000-000000000020';

    public function testJoinIsIdempotentAndPreservesExplicitRaceChannelPreferences(): void
    {
        $this->seed();
        $waitlist = $this->waitlist();
        $actor = new Actor(self::ACCOUNT, ['runner']);
        $waitlist->join($actor->accountId, self::RACE, self::MEMBERSHIP, false, true, new \DateTimeImmutable('2026-10-09Z'));
        $waitlist->join($actor->accountId, self::RACE, '00000000-0000-0000-0000-000000000021', true, false, new \DateTimeImmutable('2026-10-09T01:00:00Z'));
        $membership = $waitlist->mine($actor->accountId, self::RACE);
        self::assertNotNull($membership);
        self::assertSame(self::MEMBERSHIP, $membership->id);
        self::assertSame(self::ACCOUNT, $membership->accountId);
        self::assertFalse($membership->emailEnabled);
        self::assertTrue($membership->inAppEnabled);
        self::assertEquals(1, $this->database->fetchOne('SELECT count(*) FROM race_waitlist_memberships'));
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
    }

    public function testLeavingPreservesHistoryAndRejoiningCreatesANewMembership(): void
    {
        $this->seed();
        $waitlist = $this->waitlist();
        $actor = new Actor(self::ACCOUNT, ['runner']);
        $now = new \DateTimeImmutable('2026-10-09Z');
        $waitlist->join($actor->accountId, self::RACE, self::MEMBERSHIP, true, true, $now);
        $waitlist->leave($actor->accountId, self::MEMBERSHIP, $now->modify('+1 hour'));
        $waitlist->leave($actor->accountId, self::MEMBERSHIP, $now->modify('+2 hours'));
        self::assertNull($waitlist->mine($actor->accountId, self::RACE));
        self::assertSame('2026-10-09 01:00:00+00', $this->database->fetchOne('SELECT ended_at::text FROM race_waitlist_memberships'));
        $waitlist->join($actor->accountId, self::RACE, '00000000-0000-0000-0000-000000000021', false, false, $now->modify('+3 hours'));
        self::assertEquals(2, $this->database->fetchOne('SELECT count(*) FROM race_waitlist_memberships'));
        self::assertSame('00000000-0000-0000-0000-000000000021', $waitlist->mine($actor->accountId, self::RACE)?->id);
    }

    public function testOnlyTheOwnerCanChangeExplicitPreferencesEvenAnAdministrator(): void
    {
        $this->seed();
        $waitlist = $this->waitlist();
        $actor = new Actor(self::ACCOUNT, ['runner']);
        $now = new \DateTimeImmutable('2026-10-09Z');
        $waitlist->join($actor->accountId, self::RACE, self::MEMBERSHIP, true, true, $now);
        $waitlist->preferences($actor->accountId, self::MEMBERSHIP, false, false);
        self::assertFalse($waitlist->mine($actor->accountId, self::RACE)?->emailEnabled);
        self::assertFalse($waitlist->mine($actor->accountId, self::RACE)->inAppEnabled);
        self::assertNull($waitlist->mine((new Actor(self::OTHER, ['admin']))->accountId, self::RACE));
        foreach (['preferences', 'leave'] as $operation) {
            try {
                if ('preferences' === $operation) {
                    $waitlist->preferences((new Actor(self::OTHER, ['admin']))->accountId, self::MEMBERSHIP, true, true);
                } else {
                    $waitlist->leave((new Actor(self::OTHER, ['admin']))->accountId, self::MEMBERSHIP, $now);
                }
                self::fail('Another account must not mutate the membership.');
            } catch (\DomainException $error) {
                self::assertSame('Membership is unavailable.', $error->getMessage());
            }
        }
        self::assertFalse($waitlist->mine($actor->accountId, self::RACE)?->inAppEnabled);
    }

    public function testPreferenceChangesCoordinateWithTheSharedRaceRowLock(): void
    {
        $this->seed();
        $actor = new Actor(self::ACCOUNT, ['runner']);
        $this->waitlist()->join($actor->accountId, self::RACE, self::MEMBERSHIP, true, true, new \DateTimeImmutable('2026-10-09Z'));
        $competing = \Doctrine\DBAL\DriverManager::getConnection($this->database->getParams());
        $competing->executeStatement("SET lock_timeout = '100ms'");
        $this->database->beginTransaction();
        $this->database->fetchOne('SELECT id FROM races WHERE id = ? FOR UPDATE', [self::RACE]);
        try {
            (new ManageRaceWaitlist(new DbalWaitlistStore($competing), new DbalTransactionManager($competing), new \App\Infrastructure\Operations\DbalAuditWriter($competing), self::getContainer()->get(\App\Application\Identity\IdentityEffects::class)))->preferences($actor->accountId, self::MEMBERSHIP, false, false);
            self::fail('Membership changes must wait for the common race boundary.');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
        } finally {
            $this->database->rollBack();
            $competing->close();
        }
        self::assertTrue($this->waitlist()->mine($actor->accountId, self::RACE)?->emailEnabled);
        $this->waitlist()->preferences($actor->accountId, self::MEMBERSHIP, false, false);
        self::assertFalse($this->waitlist()->mine($actor->accountId, self::RACE)?->emailEnabled);
    }

    public function testInactiveUnverifiedAndUnpublishedRacesCannotJoinAndEndedPreferencesCannotChange(): void
    {
        $this->seed();
        $actor = new Actor(self::ACCOUNT, ['runner']);
        $now = new \DateTimeImmutable('2026-10-09Z');
        foreach (["UPDATE accounts SET lifecycle_state='suspended' WHERE id='".self::ACCOUNT."'", "UPDATE accounts SET lifecycle_state='active',verified_at=NULL WHERE id='".self::ACCOUNT."'", "UPDATE accounts SET verified_at='2026-10-09Z'; UPDATE races SET publication_state='draft'"] as $sql) {
            $this->database->executeStatement($sql);
            try {
                $this->waitlist()->join($actor->accountId, self::RACE, self::MEMBERSHIP, true, true, $now);
                self::fail('Ineligible membership must be rejected.');
            } catch (\DomainException) {
                self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM race_waitlist_memberships'));
            }
        }
        $this->database->executeStatement("UPDATE races SET publication_state='published'");
        $this->waitlist()->join($actor->accountId, self::RACE, self::MEMBERSHIP, true, true, $now);
        $this->database->executeStatement("UPDATE races SET publication_state='draft'");
        $this->waitlist()->leave($actor->accountId, self::MEMBERSHIP, $now);
        $this->expectException(\DomainException::class);
        $this->waitlist()->preferences($actor->accountId, self::MEMBERSHIP, false, false);
    }

    public function testNewsletterOptOutNeverChangesExplicitRaceMembership(): void
    {
        $this->seed();
        $this->waitlist()->join(self::ACCOUNT, self::RACE, self::MEMBERSHIP, true, true, new \DateTimeImmutable('2026-10-09Z'));
        $this->database->executeStatement("INSERT INTO newsletter_subscriptions(id,email,email_normalized,subscribed,join_at) VALUES (?,'runner@example.test','runner@example.test',true,'2026-10-09Z')", ['00000000-0000-0000-0000-000000000030']);
        $this->database->executeStatement("UPDATE newsletter_subscriptions SET subscribed=false,quit_at='2026-10-09Z',quit_reason='user' ");
        $membership = $this->waitlist()->mine(self::ACCOUNT, self::RACE);
        self::assertNotNull($membership);
        self::assertSame(self::MEMBERSHIP, $membership->id);
        self::assertTrue($membership->emailEnabled);
        self::assertTrue($membership->inAppEnabled);
    }

    public function testMembershipWithoutAlertsIsAllowedButUnapprovedChannelsCannotBeGranted(): void
    {
        $this->seed();
        $waitlist = $this->waitlist(null, null);
        $now = new \DateTimeImmutable('2026-10-09Z');
        try {
            $waitlist->join(self::ACCOUNT, self::RACE, self::MEMBERSHIP, true, false, $now);
            self::fail('A new race email grant requires a configured policy version.');
        } catch (\DomainException $error) {
            self::assertInstanceOf(\App\Application\Availability\UnconfiguredRaceNotificationPolicy::class, $error);
            self::assertSame('Race notification policy is not configured.', $error->getMessage());
        }
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM race_waitlist_memberships'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        $waitlist->join(self::ACCOUNT, self::RACE, self::MEMBERSHIP, false, false, $now);
        self::assertNotNull($waitlist->mine(self::ACCOUNT, self::RACE));
        try {
            $waitlist->preferences(self::ACCOUNT, self::MEMBERSHIP, false, true);
            self::fail('A new race in-app grant also requires a configured policy version.');
        } catch (\DomainException) {
            self::assertFalse($waitlist->mine(self::ACCOUNT, self::RACE)->inAppEnabled);
        }
    }

    public function testRaceChannelEvidenceIsIndependentAndRetriesDoNotDuplicateIt(): void
    {
        $this->seed();
        $waitlist = $this->waitlist();
        $now = new \DateTimeImmutable('2026-10-09Z');
        $waitlist->join(self::ACCOUNT, self::RACE, self::MEMBERSHIP, true, false, $now);
        $waitlist->join(self::ACCOUNT, self::RACE, '00000000-0000-0000-0000-000000000021', false, true, $now);
        $waitlist->preferences(self::ACCOUNT, self::MEMBERSHIP, true, false);
        $rows = $this->database->fetchAllAssociative("SELECT actor_account_id,subject_id,occurred_at,authority_snapshot FROM administrative_audit WHERE action='race.notification_preference_granted'");
        self::assertCount(1, $rows);
        self::assertSame(self::ACCOUNT, $rows[0]['actor_account_id']);
        self::assertSame(self::MEMBERSHIP, $rows[0]['subject_id']);
        self::assertIsString($rows[0]['occurred_at']);
        self::assertEquals($now, new \DateTimeImmutable($rows[0]['occurred_at']));
        self::assertIsString($rows[0]['authority_snapshot']);
        self::assertEquals(['scope' => 'self_service', 'source' => 'race_waitlist', 'race_id' => self::RACE, 'purpose' => 'race_notification_email', 'policy_version' => 'test-approved-race-email-v1', 'previous_enabled' => false, 'enabled' => true], json_decode($rows[0]['authority_snapshot'], true, 512, JSON_THROW_ON_ERROR));
        $waitlist->preferences(self::ACCOUNT, self::MEMBERSHIP, true, true);
        self::assertSame('test-approved-race-in-app-v2', $this->database->fetchOne("SELECT authority_snapshot->>'policy_version' FROM administrative_audit WHERE authority_snapshot->>'purpose'='race_notification_in_app'"));
        $unconfigured = $this->waitlist(null, null);
        $unconfigured->preferences(self::ACCOUNT, self::MEMBERSHIP, false, true);
        $unconfigured->leave(self::ACCOUNT, self::MEMBERSHIP, $now->modify('+1 hour'));
        $unconfigured->leave(self::ACCOUNT, self::MEMBERSHIP, $now->modify('+2 hours'));
        self::assertSame(2, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='race.notification_preference_withdrawn'"));
        self::assertSame(2, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='race.notification_preference_withdrawn' AND authority_snapshot->'policy_version'='null'::jsonb"));
        self::assertFalse($this->database->fetchOne('SELECT optional_email_enabled FROM accounts WHERE id=?', [self::ACCOUNT]));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
    }

    public function testEvidenceStorageFailureRollsBackJoinPreferencesAndLeave(): void
    {
        $this->seed();
        $waitlist = $this->waitlist();
        $now = new \DateTimeImmutable('2026-10-09Z');
        $this->database->executeStatement("CREATE FUNCTION fail_race_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Controlled consent storage failure'; END; $$");
        $this->database->executeStatement("CREATE TRIGGER reject_race_grant BEFORE INSERT ON administrative_audit FOR EACH ROW WHEN (NEW.authority_snapshot->>'purpose'='race_notification_in_app') EXECUTE FUNCTION fail_race_audit()");
        try {
            $waitlist->join(self::ACCOUNT, self::RACE, self::MEMBERSHIP, true, true, $now);
            self::fail('Partial membership and first-channel evidence must roll back.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM race_waitlist_memberships'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        }
        $this->database->executeStatement('DROP TRIGGER reject_race_grant ON administrative_audit');
        $waitlist->join(self::ACCOUNT, self::RACE, self::MEMBERSHIP, true, true, $now);
        $this->database->executeStatement("CREATE TRIGGER reject_race_withdrawal BEFORE INSERT ON administrative_audit FOR EACH ROW WHEN (NEW.action='race.notification_preference_withdrawn') EXECUTE FUNCTION fail_race_audit()");
        foreach (['preferences', 'leave'] as $operation) {
            try {
                if ('preferences' === $operation) {
                    $waitlist->preferences(self::ACCOUNT, self::MEMBERSHIP, false, false);
                } else {
                    $waitlist->leave(self::ACCOUNT, self::MEMBERSHIP, $now->modify('+1 hour'));
                }
                self::fail('Membership mutation must roll back with failed withdrawal evidence.');
            } catch (\Doctrine\DBAL\Exception\DriverException) {
                self::assertTrue($waitlist->mine(self::ACCOUNT, self::RACE)?->emailEnabled);
                self::assertTrue($waitlist->mine(self::ACCOUNT, self::RACE)->inAppEnabled);
                self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
            }
        }
        $this->database->executeStatement('DROP TRIGGER reject_race_withdrawal ON administrative_audit');
        $waitlist->leave(self::ACCOUNT, self::MEMBERSHIP, $now->modify('+1 hour'));
        $waitlist->join(self::ACCOUNT, self::RACE, '00000000-0000-0000-0000-000000000021', false, false, $now->modify('+2 hours'));
        $waitlist->leave(self::ACCOUNT, self::MEMBERSHIP, $now->modify('+3 hours'));
        self::assertSame('00000000-0000-0000-0000-000000000021', $waitlist->mine(self::ACCOUNT, self::RACE)?->id);
        self::assertSame(4, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testWaitingPreferenceWriterRereadsCommittedChannelStateUnderTheRaceLock(): void
    {
        $this->seed();
        $waitlist = $this->waitlist();
        $waitlist->join(self::ACCOUNT, self::RACE, self::MEMBERSHIP, true, false, new \DateTimeImmutable('2026-10-09Z'));
        $name = 'consent_parent_'.bin2hex(random_bytes(4));
        $this->database->executeStatement("SELECT set_config('application_name',?,false)", [$name]);
        $code = <<<'PHP'
require 'vendor/autoload.php';
$database=Doctrine\DBAL\DriverManager::getConnection(json_decode(getenv('RACE_TEST_PARAMS'),true,512,JSON_THROW_ON_ERROR));
$effects=new App\Infrastructure\Identity\OutboxIdentityEffects(new App\Infrastructure\Operations\DbalOutboxWriter($database),new Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory([]),new App\Infrastructure\Operations\AesCredentialTokenProtector('credential-token-secret-for-tests-0123456789'));
$manager=new App\Application\Availability\ManageRaceWaitlist(new App\Infrastructure\Availability\DbalWaitlistStore($database),new App\Infrastructure\Operations\DbalTransactionManager($database),new App\Infrastructure\Operations\DbalAuditWriter($database),$effects);
$database->beginTransaction();
$database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE',['00000000-0000-0000-0000-000000000010']);
echo 'locked'; flush();
$until=microtime(true)+5;
while(microtime(true)<$until){
 if(1===$database->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'",[getenv('RACE_PARENT_NAME')])){
  $manager->preferences('00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000020',false,false);
  $database->commit(); echo 'withdrawn'; exit(0);
 }
 usleep(10000);
}
$database->rollBack(); exit(2);
PHP;
        $process = new \Symfony\Component\Process\Process(['php', '-r', $code], dirname(__DIR__, 3), ['RACE_TEST_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR), 'RACE_PARENT_NAME' => $name]);
        $process->setTimeout(10)->start();
        try {
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'locked')));
            $waitlist->preferences(self::ACCOUNT, self::MEMBERSHIP, false, false);
            self::assertSame(0, $process->wait());
            self::assertSame('lockedwithdrawn', $process->getOutput());
        } finally {
            $process->stop();
        }
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='race.notification_preference_withdrawn'"));
        self::assertFalse($waitlist->mine(self::ACCOUNT, self::RACE)?->emailEnabled);
    }

    public function testStringifiedDriverCannotMutateOrExposeMembershipPreferences(): void
    {
        $this->seed();
        $this->waitlist()->join(self::ACCOUNT, self::RACE, self::MEMBERSHIP, true, true, new \DateTimeImmutable('2026-10-09Z'));
        $database = $this->stringifiedDatabase();
        $waitlist = new ManageRaceWaitlist(new DbalWaitlistStore($database), new DbalTransactionManager($database), new \App\Infrastructure\Operations\DbalAuditWriter($database), self::getContainer()->get(\App\Application\Identity\IdentityEffects::class));
        try {
            foreach (['mine', 'preferences'] as $operation) {
                try {
                    if ('mine' === $operation) {
                        $waitlist->mine(self::ACCOUNT, self::RACE);
                    } else {
                        $waitlist->preferences(self::ACCOUNT, self::MEMBERSHIP, false, false);
                    }
                    self::fail('Stored channel types must be verified before projection or mutation.');
                } catch (\UnexpectedValueException $error) {
                    self::assertSame('Invalid stored membership.', $error->getMessage());
                }
            }
        } finally {
            $database->close();
        }
        self::assertTrue($this->waitlist()->mine(self::ACCOUNT, self::RACE)?->emailEnabled);
        self::assertTrue($this->waitlist()->mine(self::ACCOUNT, self::RACE)->inAppEnabled);
        self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
    }

    private function waitlist(?string $emailPolicy = 'test-approved-race-email-v1', ?string $inAppPolicy = 'test-approved-race-in-app-v2'): ManageRaceWaitlist
    {
        $effects = self::getContainer()->get(\App\Application\Identity\IdentityEffects::class);

        return new ManageRaceWaitlist(new DbalWaitlistStore($this->database), new DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database), $effects, $emailPolicy, $inAppPolicy);
    }

    private function seed(): void
    {
        $this->database->executeStatement("INSERT INTO accounts(id,roles,terms_version,terms_accepted_at,verified_at) VALUES (?, '[\"runner\"]','v1','2026-10-09Z','2026-10-09Z'), (?, '[\"admin\"]','v1','2026-10-09Z','2026-10-09Z')", [self::ACCOUNT, self::OTHER]);
        $this->database->executeStatement("INSERT INTO races(id,name,starts_at,timezone,sport_code,distance_metres,city,country_code,publication_state,created_at,updated_at) VALUES (?,'Marathon de Paris','2030-04-10Z','Europe/Paris','running',42195,'Paris','FR','published','2026-10-09Z','2026-10-09Z')", [self::RACE]);
    }
}
