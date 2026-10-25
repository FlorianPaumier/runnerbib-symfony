<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Application\Notifications\RecipientInbox;
use App\Infrastructure\Notifications\DbalNotificationStore;
use App\Infrastructure\Operations\DbalTransactionManager;
use App\Tests\Support\IsolatedWebTestCase;

final class RecipientInboxTest extends IsolatedWebTestCase
{
    private const ACCOUNT = '00000000-0000-0000-0000-000000000001';
    private const OTHER = '00000000-0000-0000-0000-000000000002';
    private const MESSAGE = '00000000-0000-0000-0000-000000000020';

    public function testDeduplicatedEssentialMessagesRemainVisibleWhenOptionalChannelsAreDisabled(): void
    {
        $this->seed();
        $inbox = $this->inbox();
        $now = new \DateTimeImmutable('2026-10-09Z');
        $inbox->record(self::MESSAGE, self::ACCOUNT, 'account:verified:1', 'account', '{"message":"Compte confirmé"}', $now);
        $inbox->record('00000000-0000-0000-0000-000000000021', self::ACCOUNT, 'account:verified:1', 'account', '{"message":"Duplicate"}', $now);
        $messages = $inbox->messages(self::ACCOUNT);
        self::assertCount(1, $messages);
        self::assertSame(self::MESSAGE, $messages[0]->id);
        self::assertSame(['message' => 'Compte confirmé'], json_decode($messages[0]->privatePayload, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['email' => 'pending', 'in_app' => 'accepted'], $messages[0]->deliveryState);
        self::assertNull($messages[0]->readAt);
        self::assertSame([], $inbox->messages(self::OTHER));
    }

    public function testIndependentOptionalChannelsNeverSuppressEssentialMessages(): void
    {
        $this->seed();
        $this->database->executeStatement('UPDATE accounts SET optional_email_enabled = true, optional_in_app_enabled = false WHERE id = ?', [self::ACCOUNT]);
        $now = new \DateTimeImmutable('2026-10-09Z');
        $inbox = $this->inbox();
        $inbox->record(self::MESSAGE, self::ACCOUNT, 'marketing:1', 'marketing', '{}', $now);
        self::assertSame([], $inbox->messages(self::ACCOUNT));
        $storedState = $this->database->fetchOne('SELECT delivery_state::text FROM notifications');
        self::assertIsString($storedState);
        self::assertSame(['email' => 'pending', 'in_app' => 'skipped'], json_decode($storedState, true, flags: JSON_THROW_ON_ERROR));
        $this->database->executeStatement('UPDATE accounts SET optional_email_enabled = false, optional_in_app_enabled = true WHERE id = ?', [self::ACCOUNT]);
        $inbox->record('00000000-0000-0000-0000-000000000021', self::ACCOUNT, 'marketing:2', 'marketing', '{}', $now);
        self::assertSame(['email' => 'skipped', 'in_app' => 'accepted'], $inbox->messages(self::ACCOUNT)[0]->deliveryState);
        $this->database->executeStatement('UPDATE accounts SET optional_email_enabled = false, optional_in_app_enabled = false WHERE id = ?', [self::ACCOUNT]);
        $inbox->record('00000000-0000-0000-0000-000000000022', self::ACCOUNT, 'payment:1', 'payment', '{}', $now);
        self::assertCount(2, $inbox->messages(self::ACCOUNT));
        self::assertSame(['email' => 'pending', 'in_app' => 'accepted'], $inbox->messages(self::ACCOUNT)[0]->deliveryState);
    }

    public function testReadStateAndDeliveryOutcomesAreRecipientScopedAndRepeatSafe(): void
    {
        $this->seed();
        $inbox = $this->inbox();
        $now = new \DateTimeImmutable('2026-10-09Z');
        $inbox->record(self::MESSAGE, self::ACCOUNT, 'account:1', 'account', '{}', $now);
        try {
            $inbox->read(self::OTHER, self::MESSAGE, $now);
            self::fail('Another recipient must not change read state.');
        } catch (\DomainException $error) {
            self::assertSame('Notification is unavailable.', $error->getMessage());
        }
        self::assertNull($inbox->messages(self::ACCOUNT)[0]->readAt);
        $inbox->read(self::ACCOUNT, self::MESSAGE, $now);
        $inbox->read(self::ACCOUNT, self::MESSAGE, $now->modify('+1 hour'));
        self::assertEquals($now, $inbox->messages(self::ACCOUNT)[0]->readAt);
        $inbox->outcome(self::MESSAGE, 'email', 'accepted');
        self::assertSame(['email' => 'accepted', 'in_app' => 'accepted'], $inbox->messages(self::ACCOUNT)[0]->deliveryState);
    }

    public function testExplicitRaceChannelsRemainIndependentOfGlobalOptionalDefaults(): void
    {
        $this->seed();
        $inbox = $this->inbox();
        $inbox->recordAvailability(self::MESSAGE, self::ACCOUNT, 'availability:1', '{}', new \DateTimeImmutable('2026-10-09Z'), true, true);
        self::assertSame(['email' => 'pending', 'in_app' => 'accepted'], $inbox->messages(self::ACCOUNT)[0]->deliveryState);
    }

    public function testOptionalPreferenceMigrationRollsBackAndReplaysWithoutChangingEmail(): void
    {
        $this->seed();
        $this->database->executeStatement('UPDATE accounts SET optional_email_enabled = true, optional_in_app_enabled = true WHERE id = ?', [self::ACCOUNT]);
        $this->migration('--down');
        self::assertFalse($this->database->fetchOne("SELECT column_name FROM information_schema.columns WHERE table_name='accounts' AND column_name='optional_in_app_enabled'"));
        self::assertTrue($this->database->fetchOne('SELECT optional_email_enabled FROM accounts WHERE id=?', [self::ACCOUNT]));
        $this->migration('--up');
        self::assertFalse($this->database->fetchOne('SELECT optional_in_app_enabled FROM accounts WHERE id=?', [self::ACCOUNT]));
        self::assertTrue($this->database->fetchOne('SELECT optional_email_enabled FROM accounts WHERE id=?', [self::ACCOUNT]));
    }

    private function migration(string $direction): void
    {
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);
        (new \Symfony\Component\Process\Process(['php', 'bin/console', 'doctrine:migrations:execute', 'DoctrineMigrations\\Version20261009000100', $direction, '--no-interaction'], dirname(__DIR__, 3), ['DATABASE_URL' => $url, 'APP_ENV' => 'test']))->setTimeout(120)->mustRun();
    }

    public function testUnknownCategoriesAndInvalidPrivatePayloadsFailBeforePersistence(): void
    {
        $this->seed();
        foreach ([['unknown', 'event', '{}'], ['availability', 'event', '{}'], ['account', '', '{}'], ['account', '  ', '{}'], ['account', 'event', '[]'], ['account', 'event', '{invalid']] as [$category, $eventKey, $payload]) {
            try {
                $this->inbox()->record(self::MESSAGE, self::ACCOUNT, $eventKey, $category, $payload, new \DateTimeImmutable('2026-10-09Z'));
                self::fail('Invalid notification must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertNull($error->getPrevious());
            }
        }
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM notifications'));
    }

    public function testBoundedCursorPaginationPreservesEqualTimestampMessages(): void
    {
        $this->seed();
        $inbox = $this->inbox();
        $now = new \DateTimeImmutable('2026-10-09Z');
        $inbox->record(self::MESSAGE, self::ACCOUNT, 'account:1', 'account', '{}', $now);
        $inbox->record('00000000-0000-0000-0000-000000000021', self::ACCOUNT, 'account:2', 'account', '{}', $now);
        $page = $inbox->messages(self::ACCOUNT, null, 1);
        self::assertCount(1, $page);
        self::assertSame('00000000-0000-0000-0000-000000000021', $page[0]->id);
        $cursor = new \App\Application\Notifications\InboxCursor($page[0]->createdAt, $page[0]->id);
        $next = $inbox->messages(self::ACCOUNT, $cursor, 1);
        self::assertCount(1, $next);
        self::assertSame(self::MESSAGE, $next[0]->id);
        self::assertSame([], $inbox->messages(self::ACCOUNT, new \App\Application\Notifications\InboxCursor($next[0]->createdAt, $next[0]->id), 1));
    }

    public function testInvalidAndTerminalOutcomesAndPaginationBoundsCannotMutateAnInbox(): void
    {
        $this->seed();
        $inbox = $this->inbox();
        $now = new \DateTimeImmutable('2026-10-09Z');
        $inbox->record(self::MESSAGE, self::ACCOUNT, 'account:1', 'account', '{}', $now);
        foreach ([['sms', 'accepted'], ['email', 'delivered']] as [$channel, $outcome]) {
            try {
                $inbox->outcome(self::MESSAGE, $channel, $outcome);
                self::fail('Invalid outcome must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Invalid channel outcome.', $error->getMessage());
            }
        }
        $inbox->outcome(self::MESSAGE, 'email', 'accepted');
        $inbox->outcome(self::MESSAGE, 'email', 'accepted');
        try {
            $inbox->outcome(self::MESSAGE, 'email', 'failed');
            self::fail('Accepted outcome must not regress.');
        } catch (\DomainException) {
            self::assertSame('accepted', $inbox->messages(self::ACCOUNT)[0]->deliveryState['email']);
        }
        foreach ([0, 101] as $limit) {
            try {
                $inbox->messages(self::ACCOUNT, null, $limit);
                self::fail('Invalid page limit must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Inbox limit must be between 1 and 100.', $error->getMessage());
            }
        }
    }

    public function testAnonymizedRecipientsCannotAcquireNewPrivateNotifications(): void
    {
        $this->seed();
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='anonymized', anonymized_at='2026-10-09Z' WHERE id=?", [self::ACCOUNT]);
        $this->expectException(\DomainException::class);
        $this->inbox()->record(self::MESSAGE, self::ACCOUNT, 'account:1', 'account', '{}', new \DateTimeImmutable('2026-10-09Z'));
    }

    public function testMalformedStoredChannelOutcomeFailsWithoutExposingPrivateContent(): void
    {
        $this->seed();
        $this->inbox()->record(self::MESSAGE, self::ACCOUNT, 'account:1', 'account', '{"private":"secret"}', new \DateTimeImmutable('2026-10-09Z'));
        $this->database->executeStatement('UPDATE notifications SET delivery_state=?', ['{"email":false,"in_app":"accepted"}']);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid delivery outcome.');
        $this->inbox()->messages(self::ACCOUNT);
    }

    public function testStringifiedDriverCannotRecordNotificationsOrExposeAnInbox(): void
    {
        $this->seed();
        $now = new \DateTimeImmutable('2026-10-09Z');
        $this->inbox()->record(self::MESSAGE, self::ACCOUNT, 'account:existing', 'account', '{"private":"protected"}', $now);
        $database = $this->stringifiedDatabase();
        $inbox = new RecipientInbox(new DbalNotificationStore($database), new DbalTransactionManager($database), new \App\Infrastructure\Notifications\DbalNotificationPreferenceStore($database));
        try {
            foreach (['record', 'messages'] as $operation) {
                try {
                    if ('record' === $operation) {
                        $inbox->record('00000000-0000-0000-0000-000000000099', self::ACCOUNT, 'account:new', 'account', '{}', $now);
                    } else {
                        $inbox->messages(self::ACCOUNT);
                    }
                    self::fail('Native scalar type mismatch must not produce a notification or private projection.');
                } catch (\UnexpectedValueException $error) {
                    self::assertSame('record' === $operation ? 'Invalid stored preferences.' : 'Invalid stored notification.', $error->getMessage());
                }
            }
        } finally {
            $database->close();
        }
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM notifications'));
        self::assertNull($this->database->fetchOne('SELECT read_at FROM notifications'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testAvailabilityPayloadValidationAndInclusiveInboxLimit(): void
    {
        $this->seed();
        foreach ([[' ', '{}'], ['event', '[]'], ['event', '{invalid']] as [$key, $payload]) {
            try {
                $this->inbox()->recordAvailability(self::MESSAGE, self::ACCOUNT, $key, $payload, new \DateTimeImmutable('2026-10-09Z'), true, true);
                self::fail('Malformed availability notification was recorded.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM notifications'));
            }
        }
        self::assertSame([], $this->inbox()->messages(self::ACCOUNT, limit: 100));
    }

    private function inbox(): RecipientInbox
    {
        return new RecipientInbox(new DbalNotificationStore($this->database), new DbalTransactionManager($this->database), new \App\Infrastructure\Notifications\DbalNotificationPreferenceStore($this->database));
    }

    private function seed(): void
    {
        $this->database->executeStatement("INSERT INTO accounts(id,roles,terms_version,terms_accepted_at,verified_at) VALUES (?, '[\"runner\"]','v1','2026-10-09Z','2026-10-09Z'), (?, '[\"admin\"]','v1','2026-10-09Z','2026-10-09Z')", [self::ACCOUNT, self::OTHER]);
    }
}
