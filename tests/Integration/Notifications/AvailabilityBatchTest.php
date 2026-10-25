<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Application\Availability\FindOfferAccess;
use App\Application\Notifications\ScheduleAvailabilityBatch;
use App\Infrastructure\Availability\DbalAvailabilityAccessStore;
use App\Infrastructure\Notifications\DbalAvailabilityBatchStore;
use App\Infrastructure\Operations\DbalEffectConsumer;
use App\Tests\Integration\Availability\AvailabilityTestCase;

final class AvailabilityBatchTest extends AvailabilityTestCase
{
    private const RUN = '00000000-0000-0000-0000-000000000030';

    public function testSchedulingPersistsTheNextFiveMinuteBatchAndPendingDeduplicatedChannels(): void
    {
        $this->seedOffer();
        $scheduler = $this->scheduler();
        self::assertTrue($scheduler->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z')));
        self::assertFalse($scheduler->schedule(self::EVENT, '00000000-0000-0000-0000-000000000031', new \DateTimeImmutable('2026-10-10T10:02:00Z')));
        self::assertSame('2026-10-10 10:05:00+00', $this->database->fetchOne('SELECT scheduled_at::text FROM scheduled_runs'));
        self::assertSame('pending', $this->database->fetchOne('SELECT state FROM scheduled_runs'));
        self::assertEquals(1, $this->database->fetchOne('SELECT count(*) FROM notifications'));
        self::assertSame(self::EVENT, $this->database->fetchOne('SELECT availability_event_id::text FROM notifications'));
        self::assertSame('2026-10-10 11:01:00+00', $this->database->fetchOne('SELECT deadline::text FROM notifications'));
        $state = $this->database->fetchOne('SELECT delivery_state::text FROM notifications');
        self::assertIsString($state);
        self::assertSame(['email' => 'pending', 'in_app' => 'pending', 'batch_id' => self::RUN], json_decode($state, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testDueBatchPublishesInAppAndCreatesOneDurableEmailDigestWithoutEarlyDelivery(): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $runner = new \App\Application\Notifications\RunAvailabilityBatch(new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new \App\Infrastructure\Operations\DbalTransactionManager($this->database));
        self::assertFalse($runner->runNext(new \DateTimeImmutable('2026-10-10T10:04:59Z')));
        self::assertTrue($runner->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z')));
        self::assertFalse($runner->runNext(new \DateTimeImmutable('2026-10-10T10:05:01Z')));
        self::assertSame('accepted', $this->database->fetchOne("SELECT delivery_state->>'in_app' FROM notifications"));
        self::assertEquals(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='notifications.availability_digest'"));
        self::assertSame('completed', $this->database->fetchOne('SELECT state FROM scheduled_runs'));
        self::assertSame('2026-10-10 11:01:00+00', $this->database->fetchOne('SELECT deadline::text FROM notifications'));
    }

    public function testBatchRevalidatesExplicitPreferencesWithoutExtendingDeadlines(): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $this->database->executeStatement('UPDATE race_waitlist_memberships SET email_enabled=false,in_app_enabled=false');
        $runner = new \App\Application\Notifications\RunAvailabilityBatch(new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new \App\Infrastructure\Operations\DbalTransactionManager($this->database));
        self::assertTrue($runner->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z')));
        self::assertSame('skipped', $this->database->fetchOne("SELECT delivery_state->>'email' FROM notifications"));
        self::assertSame('skipped', $this->database->fetchOne("SELECT delivery_state->>'in_app' FROM notifications"));
        self::assertEquals(0, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='notifications.availability_digest'"));
    }

    public function testRunProcessesAtMostOneHundredNotificationsAndResumesTheSameBatch(): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $this->database->executeStatement("INSERT INTO notifications(id,recipient_id,event_key,category,payload_version,private_payload,availability_event_id,deadline,created_at,delivery_state) SELECT gen_random_uuid(),n.recipient_id,n.event_key||':'||g,n.category,n.payload_version,n.private_payload,n.availability_event_id,n.deadline,n.created_at,n.delivery_state FROM notifications n CROSS JOIN generate_series(1,100) g");
        $runner = new \App\Application\Notifications\RunAvailabilityBatch(new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new \App\Infrastructure\Operations\DbalTransactionManager($this->database));
        self::assertTrue($runner->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z')));
        self::assertEquals(100, $this->database->fetchOne("SELECT count(*) FROM notifications WHERE delivery_state->>'in_app'='accepted'"));
        self::assertSame('pending', $this->database->fetchOne('SELECT state FROM scheduled_runs'));
        self::assertTrue($runner->runNext(new \DateTimeImmutable('2026-10-10T10:05:01Z')));
        self::assertEquals(101, $this->database->fetchOne("SELECT count(*) FROM notifications WHERE delivery_state->>'in_app'='accepted'"));
        self::assertSame('completed', $this->database->fetchOne('SELECT state FROM scheduled_runs'));
    }

    public function testDigestDeliveryRevalidatesWithdrawnOfferAndNeverCallsTheMailPort(): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $runner = new \App\Application\Notifications\RunAvailabilityBatch(new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new \App\Infrastructure\Operations\DbalTransactionManager($this->database));
        $runner->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z'));
        $this->database->beginTransaction();
        $this->publisher()->invalidate(self::LISTING, new \DateTimeImmutable('2026-10-10T10:05:30Z'), 'withdrawn');
        $this->database->commit();
        $sender = new ControlledAvailabilityMail();
        $delivery = new \App\Application\Notifications\DeliverAvailabilityDigest(new \App\Infrastructure\Notifications\DbalAvailabilityDigestStore($this->database), new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), $sender);
        $effectKey = $this->database->fetchOne("SELECT effect_key FROM outbox_messages WHERE event_type='notifications.availability_digest'");
        $notificationId = $this->database->fetchOne('SELECT id::text FROM notifications');
        self::assertIsString($effectKey);
        self::assertIsString($notificationId);
        self::assertFalse($delivery->deliver($effectKey, self::MEMBER, [$notificationId], new \DateTimeImmutable('2026-10-10T10:06:00Z')));
        $handler = new \App\Infrastructure\Notifications\AvailabilityMessageHandler($this->scheduler(), $delivery);
        $handler(new \App\Application\Operations\OutboxMessage(self::RUN, $effectKey, 'availability_batch', self::RUN, 1, 'notifications.availability_digest', 1, json_encode(['recipient_id' => self::MEMBER, 'notification_ids' => [$notificationId]], JSON_THROW_ON_ERROR), new \DateTimeImmutable('2026-10-10T10:05:00Z')));
        self::assertSame([], $sender->sent);
        self::assertSame('skipped', $this->database->fetchOne("SELECT delivery_state->>'email' FROM notifications"));
    }

    public function testDigestIsSentOnceWithTheOriginalDeadlineAndStableMessageIdentity(): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        (new \App\Application\Notifications\RunAvailabilityBatch(new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new \App\Infrastructure\Operations\DbalTransactionManager($this->database)))->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z'));
        $sender = new ControlledAvailabilityMail();
        $delivery = new \App\Application\Notifications\DeliverAvailabilityDigest(new \App\Infrastructure\Notifications\DbalAvailabilityDigestStore($this->database), new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), $sender);
        $effectKey = $this->database->fetchOne("SELECT effect_key FROM outbox_messages WHERE event_type='notifications.availability_digest'");
        $notificationId = $this->database->fetchOne('SELECT id::text FROM notifications');
        self::assertIsString($effectKey);
        self::assertIsString($notificationId);
        self::assertTrue($delivery->deliver($effectKey, self::MEMBER, [$notificationId], new \DateTimeImmutable('2026-10-10T10:06:00Z')));
        self::assertFalse($delivery->deliver($effectKey, self::MEMBER, [$notificationId], new \DateTimeImmutable('2026-10-10T10:07:00Z')));
        self::assertSame('accepted', $this->database->fetchOne("SELECT delivery_state->>'email' FROM notifications"));
        $this->database->executeStatement("UPDATE notifications SET delivery_state=jsonb_set(delivery_state,'{email}','\"pending\"')");
        self::assertFalse($delivery->deliver($effectKey, self::MEMBER, [$notificationId], new \DateTimeImmutable('2026-10-10T10:08:00Z')));
        self::assertCount(1, $sender->sent);
        self::assertSame('batch-member@example.test', $sender->sent[0]->email);
        self::assertSame('2026-10-10T11:01:00+00:00', $sender->sent[0]->offers[0]['deadline']->format(DATE_ATOM));
        self::assertSame('accepted', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages WHERE effect_key=?", [$effectKey]));
    }

    public function testAmbiguousDigestDeliveryIsJournaledAndNeverBlindlySentAgain(): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        (new \App\Application\Notifications\RunAvailabilityBatch(new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new \App\Infrastructure\Operations\DbalTransactionManager($this->database)))->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z'));
        $sender = new ControlledAvailabilityMail();
        $sender->fail = true;
        $delivery = new \App\Application\Notifications\DeliverAvailabilityDigest(new \App\Infrastructure\Notifications\DbalAvailabilityDigestStore($this->database), new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), $sender);
        $effectKey = $this->database->fetchOne("SELECT effect_key FROM outbox_messages WHERE event_type='notifications.availability_digest'");
        $notificationId = $this->database->fetchOne('SELECT id::text FROM notifications');
        self::assertIsString($effectKey);
        self::assertIsString($notificationId);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $delivery->deliver($effectKey, self::MEMBER, [$notificationId], new \DateTimeImmutable('2026-10-10T10:06:00Z'));
                self::fail('Uncertain delivery must require reconciliation.');
            } catch (\App\Application\Operations\UncertainMailDelivery $exception) {
                self::assertStringNotContainsString('Private provider', $exception->getMessage());
            }
        }
        self::assertCount(1, $sender->sent);
        self::assertSame('unknown', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages WHERE effect_key=?", [$effectKey]));
    }

    public function testOutboxAvailabilityMessageSchedulesOnceThroughTheRegisteredHandlerContract(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->seedOffer($now);
        $handler = new \App\Infrastructure\Notifications\AvailabilityMessageHandler($this->scheduler(), new \App\Application\Notifications\DeliverAvailabilityDigest(new \App\Infrastructure\Notifications\DbalAvailabilityDigestStore($this->database), new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), new ControlledAvailabilityMail()));
        self::assertTrue($handler->supports('availability.batch_ready'));
        self::assertTrue($handler->supports('notifications.availability_digest'));
        self::assertFalse($handler->supports('unrelated.event'));
        $message = new \App\Application\Operations\OutboxMessage(self::EVENT, 'availability:'.self::EVENT, 'availability_event', self::EVENT, 1, 'availability.batch_ready', 1, json_encode(['event_id' => self::EVENT], JSON_THROW_ON_ERROR), $now);
        $handler($message);
        $handler($message);
        self::assertEquals(1, $this->database->fetchOne('SELECT count(*) FROM scheduled_runs'));
        self::assertEquals(1, $this->database->fetchOne('SELECT count(*) FROM notifications'));
    }

    public function testExpiredAvailabilityIsConsumedWithoutSchedulingAnotherWindow(): void
    {
        $this->seedOffer();
        self::assertTrue($this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T11:01:00Z')));
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM scheduled_runs'));
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM notifications'));
        self::assertSame('2026-10-10 11:01:00+00', $this->database->fetchOne('SELECT priority_ends_at::text FROM availability_events'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMessages')]
    public function testOutboxHandlerRejectsInvalidMessagesBeforeCreatingAnEffect(string $type, int $version, string $payload): void
    {
        $handler = new \App\Infrastructure\Notifications\AvailabilityMessageHandler($this->scheduler(), new \App\Application\Notifications\DeliverAvailabilityDigest(new \App\Infrastructure\Notifications\DbalAvailabilityDigestStore($this->database), new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), new ControlledAvailabilityMail()));
        $this->expectException(\InvalidArgumentException::class);
        $handler(new \App\Application\Operations\OutboxMessage(self::EVENT, 'availability:'.self::EVENT, 'availability_event', self::EVENT, 1, $type, $version, $payload, new \DateTimeImmutable('2026-10-10Z')));
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function invalidMessages(): iterable
    {
        yield 'unsupported' => ['other.event', 1, '{}'];
        yield 'version' => ['availability.batch_ready', 2, '{}'];
        yield 'invalid_json' => ['availability.batch_ready', 1, '{'];
        yield 'scalar' => ['availability.batch_ready', 1, 'null'];
        yield 'missing_event' => ['availability.batch_ready', 1, '{}'];
        yield 'invalid_event' => ['availability.batch_ready', 1, '{"event_id":"invalid"}'];
        yield 'other_aggregate' => ['availability.batch_ready', 1, '{"event_id":"00000000-0000-0000-0000-000000000021"}'];
        yield 'missing_recipient' => ['notifications.availability_digest', 1, '{}'];
        yield 'invalid_recipient' => ['notifications.availability_digest', 1, '{"recipient_id":"invalid","notification_ids":[]}'];
        yield 'empty_digest' => ['notifications.availability_digest', 1, '{"recipient_id":"00000000-0000-0000-0000-000000000002","notification_ids":[]}'];
        yield 'invalid_notification' => ['notifications.availability_digest', 1, '{"recipient_id":"00000000-0000-0000-0000-000000000002","notification_ids":["invalid"]}'];
        yield 'nonstring_notification' => ['notifications.availability_digest', 1, '{"recipient_id":"00000000-0000-0000-0000-000000000002","notification_ids":[1]}'];
        yield 'associative_digest' => ['notifications.availability_digest', 1, '{"recipient_id":"00000000-0000-0000-0000-000000000002","notification_ids":{"x":"invalid"}}'];
    }

    public function testConcurrentBatchWorkersShareRaceLockOrderAndCreateOnlyOneDigest(): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $name = 'batch_'.bin2hex(random_bytes(6));
        $this->database->executeQuery("SELECT set_config('application_name',?,false)", [$name]);
        $code = <<<'CODE'
require 'vendor/autoload.php';
$database=\Doctrine\DBAL\DriverManager::getConnection(json_decode(getenv('BATCH_TEST_PARAMS'),true,flags:JSON_THROW_ON_ERROR));
$database->beginTransaction();
$database->fetchOne("SELECT id FROM races WHERE id='00000000-0000-0000-0000-000000000004' FOR UPDATE");
echo 'locked'; flush();
$deadline=microtime(true)+10;
do {
    $database->executeQuery('SELECT pg_stat_clear_snapshot()');
    $blocked=$database->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'",[getenv('BATCH_PARENT_NAME')]);
    if (1===$blocked) {
        $processed=(new \App\Application\Notifications\RunAvailabilityBatch(new \App\Infrastructure\Notifications\DbalAvailabilityBatchStore($database,new \App\Application\Availability\FindOfferAccess(new \App\Infrastructure\Availability\DbalAvailabilityAccessStore($database))),new \App\Infrastructure\Operations\DbalTransactionManager($database)))->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z'));
        $database->commit();
        echo $processed?'processed':'empty';
        exit(0);
    }
    usleep(20000);
} while (microtime(true)<$deadline);
$database->rollBack(); exit(1);
CODE;
        $process = new \Symfony\Component\Process\Process(['php', '-r', $code], dirname(__DIR__, 3), ['BATCH_TEST_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR), 'BATCH_PARENT_NAME' => $name]);
        try {
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'locked')));
            self::assertFalse((new \App\Application\Notifications\RunAvailabilityBatch(new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new \App\Infrastructure\Operations\DbalTransactionManager($this->database)))->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z')));
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertSame('lockedprocessed', $process->getOutput());
            self::assertEquals(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='notifications.availability_digest'"));
            self::assertSame('completed', $this->database->fetchOne('SELECT state FROM scheduled_runs'));
        } finally {
            $process->stop();
        }
    }

    public function testUnknownAvailabilityEventIsRejectedAndItsEffectRollsBack(): void
    {
        try {
            $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
            self::fail('Unknown events must not create batch effects.');
        } catch (\DomainException) {
            self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM consumed_effects'));
            self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM scheduled_runs'));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('eligibilityStages')]
    public function testObtainingAnotherBibForTheRaceSuppressesThePendingAlert(string $stage): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $runner = new \App\Application\Notifications\RunAvailabilityBatch(new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new \App\Infrastructure\Operations\DbalTransactionManager($this->database));
        if ('delivery' === $stage) {
            $runner->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z'));
        }
        $this->database->executeStatement("INSERT INTO bib_entitlements(id,race_id,bib_number,source_kind,current_owner_account_id) VALUES('00000000-0000-0000-0000-000000000044',?,'00444','document',?)", [self::RACE, self::MEMBER]);
        if ('batch' === $stage) {
            self::assertTrue($runner->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z')));
            self::assertSame('skipped', $this->database->fetchOne("SELECT delivery_state->>'in_app' FROM notifications"));
        } else {
            $sender = new ControlledAvailabilityMail();
            $delivery = new \App\Application\Notifications\DeliverAvailabilityDigest(new \App\Infrastructure\Notifications\DbalAvailabilityDigestStore($this->database), new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), $sender);
            $key = $this->database->fetchOne("SELECT effect_key FROM outbox_messages WHERE event_type='notifications.availability_digest'");
            $id = $this->database->fetchOne('SELECT id::text FROM notifications');
            self::assertIsString($key);
            self::assertIsString($id);
            self::assertFalse($delivery->deliver($key, self::MEMBER, [$id], new \DateTimeImmutable('2026-10-10T10:06:00Z')));
            self::assertSame([], $sender->sent);
        }
        self::assertSame('skipped', $this->database->fetchOne("SELECT delivery_state->>'email' FROM notifications"));
    }

    /** @return iterable<string,array{string}> */
    public static function eligibilityStages(): iterable
    {
        yield 'before batch' => ['batch'];
        yield 'before SMTP' => ['delivery'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('eligibilityStages')]
    public function testIncompleteStoredAvailabilityFailsClosedBeforeBatchOrSmtp(string $stage): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $runner = new \App\Application\Notifications\RunAvailabilityBatch(new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new \App\Infrastructure\Operations\DbalTransactionManager($this->database));
        if ('delivery' === $stage) {
            $runner->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z'));
        }
        $id = $this->database->fetchOne('SELECT id::text FROM notifications');
        self::assertIsString($id);
        $this->database->executeStatement('UPDATE notifications SET availability_event_id=NULL');
        $sender = new ControlledAvailabilityMail();
        try {
            if ('batch' === $stage) {
                $runner->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z'));
            } else {
                $delivery = new \App\Application\Notifications\DeliverAvailabilityDigest(new \App\Infrastructure\Notifications\DbalAvailabilityDigestStore($this->database), new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), $sender);
                $delivery->deliver('incomplete-availability', self::MEMBER, [$id], new \DateTimeImmutable('2026-10-10T10:06:00Z'));
            }
            self::fail('Missing stored event linkage must fail closed.');
        } catch (\UnexpectedValueException $error) {
            self::assertSame('batch' === $stage ? 'Invalid stored notification.' : 'Invalid stored availability notification.', $error->getMessage());
        }
        self::assertSame([], $sender->sent);
        self::assertSame('batch' === $stage ? 'pending' : 'completed', $this->database->fetchOne('SELECT state FROM scheduled_runs'));
        self::assertSame('pending', $this->database->fetchOne("SELECT delivery_state->>'email' FROM notifications"));
    }

    public function testBatchPortsRefuseUnownedTransactionsAndUnknownOfferLocksAreHarmless(): void
    {
        $this->seedOffer();
        $store = new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database)));
        $now = new \DateTimeImmutable('2026-10-10T10:05:00Z');
        foreach (['run', 'lock'] as $operation) {
            try {
                if ('run' === $operation) {
                    $store->runNext($now);
                } else {
                    $store->lockOffer(self::EVENT);
                }
                self::fail('Batch mutation accepted without an owning transaction.');
            } catch (\LogicException $failure) {
                self::assertSame('Availability batching requires an owning transaction.', $failure->getMessage());
            }
        }
        $this->database->transactional(fn () => $store->lockOffer(self::RUN));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM scheduled_runs'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM notifications'));
    }

    public function testClosedBatchCannotAcceptAnotherScheduleOrDuplicateNotifications(): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        (new \App\Application\Notifications\RunAvailabilityBatch(new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new \App\Infrastructure\Operations\DbalTransactionManager($this->database)))->runNext(new \DateTimeImmutable('2026-10-10T10:05:00Z'));
        $offer = (new FindOfferAccess(new DbalAvailabilityAccessStore($this->database)))->forEvent(self::EVENT, self::MEMBER, new \DateTimeImmutable('2026-10-10T10:04:00Z'));
        self::assertNotNull($offer);
        try {
            $this->database->transactional(fn () => (new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))))->schedule($offer, '00000000-0000-0000-0000-000000000031', new \DateTimeImmutable('2026-10-10T10:05:00Z'), new \DateTimeImmutable('2026-10-10T10:04:00Z')));
            self::fail('Completed batch accepted another schedule.');
        } catch (\DomainException $failure) {
            self::assertSame('Availability batch is already closed.', $failure->getMessage());
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM scheduled_runs'));
            self::assertSame('completed', $this->database->fetchOne('SELECT state FROM scheduled_runs'));
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM notifications'));
            self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='notifications.availability_digest'"));
        }
    }

    public function testMalformedDigestAndUnrelatedMailOutcomesCannotChangeNotificationState(): void
    {
        $this->seedOffer();
        $this->scheduler()->schedule(self::EVENT, self::RUN, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $store = new \App\Infrastructure\Notifications\DbalAvailabilityDigestStore($this->database);
        $now = new \DateTimeImmutable('2026-10-10T10:05:00Z');
        foreach ([[], array_fill(0, 101, self::EVENT)] as $ids) {
            try {
                $store->prepare(self::MEMBER, $ids, $now);
                self::fail('Invalid digest size accepted.');
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('Digest requires one to one hundred notifications.', $failure->getMessage());
            }
        }
        $id = $this->database->fetchOne('SELECT id::text FROM notifications');
        self::assertIsString($id);
        $digest = $store->prepare(self::MEMBER, [$id], $now);
        self::assertNotNull($digest);
        try {
            $store->outcome($digest, 'delivered');
            self::fail('Unrecognized mail outcome changed persisted state.');
        } catch (\InvalidArgumentException $failure) {
            self::assertSame('Invalid mail outcome.', $failure->getMessage());
            self::assertSame('pending', $this->database->fetchOne("SELECT delivery_state->>'email' FROM notifications"));
        }
        $this->database->executeStatement('UPDATE notifications SET availability_event_id=NULL');
        try {
            $store->prepare(self::MEMBER, [$id], $now);
            self::fail('Notification without an availability event became a mail offer.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame('Invalid stored availability notification.', $failure->getMessage());
            self::assertSame('pending', $this->database->fetchOne("SELECT delivery_state->>'email' FROM notifications"));
            self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='notifications.availability_digest'"));
        }
    }

    private function scheduler(): ScheduleAvailabilityBatch
    {
        return new ScheduleAvailabilityBatch(new FindOfferAccess(new DbalAvailabilityAccessStore($this->database)), new DbalAvailabilityBatchStore($this->database, new FindOfferAccess(new DbalAvailabilityAccessStore($this->database))), new DbalEffectConsumer($this->database));
    }

    private function seedOffer(?\DateTimeImmutable $availableAt = null): void
    {
        $this->seed();
        $this->database->executeStatement("INSERT INTO account_private_profiles(account_id,email,email_normalized,display_name) VALUES (?,'batch-member@example.test','batch-member@example.test','Coureuse')", [self::MEMBER]);
        $this->database->executeStatement("INSERT INTO race_waitlist_memberships(id,race_id,account_id,joined_at) VALUES (?, ?,?,'2026-10-10Z')", ['00000000-0000-0000-0000-000000000021', self::RACE, self::MEMBER]);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, $availableAt ?? new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $this->database->commit();
    }
}
