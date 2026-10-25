<?php

declare(strict_types=1);

namespace App\Tests\Integration\Availability;

final class AvailabilitySnapshotTest extends AvailabilityTestCase
{
    public function testPublicationCapturesTheImmutableExistingGroupPriceAndDeadlineWithOneDurableIntent(): void
    {
        $this->seed();
        $this->database->executeStatement("INSERT INTO race_waitlist_memberships(id,race_id,account_id,joined_at) VALUES (? ,?,?,'2026-10-10Z')", ['00000000-0000-0000-0000-000000000021', self::RACE, self::MEMBER]);
        $this->database->beginTransaction();
        $offer = $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        self::assertSame(self::EVENT, $offer->eventId);
        self::assertSame(1, $offer->offerVersion);
        self::assertSame(10000, $offer->priceCents);
        self::assertSame(1, $offer->recipientCount);
        self::assertSame('2026-10-10T11:00:00+00:00', $offer->priorityEndsAt->format(DATE_ATOM));
        $this->database->executeStatement("INSERT INTO race_waitlist_memberships(id,race_id,account_id,joined_at) VALUES (?, ?,?,'2026-10-10T10:01:00Z')", ['00000000-0000-0000-0000-000000000022', self::RACE, self::LATE]);
        $this->database->beginTransaction();
        $replay = $this->publisher()->publish('00000000-0000-0000-0000-000000000023', self::LISTING, new \DateTimeImmutable('2026-10-10T10:05:00Z'));
        $this->database->commit();
        self::assertEquals($offer, $replay);
        self::assertSame([self::MEMBER], $this->database->fetchFirstColumn('SELECT account_id::text FROM availability_recipients WHERE event_id=?', [self::EVENT]));
        self::assertEquals(1, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        self::assertSame('availability.batch_ready', $this->database->fetchOne('SELECT event_type FROM outbox_messages'));
    }

    public function testRepricingCreatesANewImmutableWindowAndInvalidatesThePreviousVersion(): void
    {
        $this->seed();
        $this->database->beginTransaction();
        $old = $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->executeStatement('UPDATE resale_listings SET offer_version=2,price_cents=9000 WHERE id=?', [self::LISTING]);
        $current = $this->publisher()->publish('00000000-0000-0000-0000-000000000024', self::LISTING, new \DateTimeImmutable('2026-10-10T10:10:00Z'));
        $this->database->commit();
        self::assertSame(2, $current->offerVersion);
        self::assertSame(9000, $current->priceCents);
        self::assertSame('2026-10-10T11:10:00+00:00', $current->priorityEndsAt->format(DATE_ATOM));
        self::assertSame('repriced', $this->database->fetchOne('SELECT invalidation_reason FROM availability_events WHERE id=?', [$old->eventId]));
        self::assertSame('10000', $this->database->fetchOne('SELECT price_cents::text FROM availability_events WHERE id=?', [$old->eventId]));
        self::assertEquals(2, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
    }

    public function testWithdrawalInvalidatesWithoutChangingTheOriginalOfferOrDeadline(): void
    {
        $this->seed();
        $this->database->beginTransaction();
        $offer = $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->executeStatement("UPDATE resale_listings SET state='withdrawn',withdrawn_at='2026-10-10T10:10:00Z' WHERE id=?", [self::LISTING]);
        $this->publisher()->invalidate(self::LISTING, new \DateTimeImmutable('2026-10-10T10:10:00Z'), 'withdrawn');
        $this->publisher()->invalidate(self::LISTING, new \DateTimeImmutable('2026-10-10T10:20:00Z'), 'withdrawn');
        $this->database->commit();
        self::assertSame('2026-10-10 10:10:00+00', $this->database->fetchOne('SELECT invalidated_at::text FROM availability_events WHERE id=?', [$offer->eventId]));
        self::assertSame('withdrawn', $this->database->fetchOne('SELECT invalidation_reason FROM availability_events WHERE id=?', [$offer->eventId]));
        self::assertSame('2026-10-10 11:00:00+00', $this->database->fetchOne('SELECT priority_ends_at::text FROM availability_events WHERE id=?', [$offer->eventId]));
    }

    public function testTheWindowIsExactly3600ElapsedSecondsAcrossTheParisClockChange(): void
    {
        $this->seed();
        $instant = (new \DateTimeImmutable('2026-10-25T02:30:00.123456+02:00'))->setTimezone(new \DateTimeZone('Europe/Paris'));
        $this->database->beginTransaction();
        $offer = $this->publisher()->publish(self::EVENT, self::LISTING, $instant);
        $this->database->commit();
        self::assertSame('2026-10-25T01:30:00.123456+00:00', $offer->priorityEndsAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('3600.000000', $this->database->fetchOne('SELECT extract(epoch FROM priority_ends_at-available_at)::text FROM availability_events'));
    }

    public function testSnapshotAndOutboxRollbackWithTheOwningTransactionAndRequireThatBoundary(): void
    {
        $this->seed();
        try {
            (new \App\Infrastructure\Operations\DbalTransactionManager($this->database))->run(function (): void {
                $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10Z'));
                throw new \RuntimeException('Controlled rollback.');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('Controlled rollback.', $error->getMessage());
        }
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        foreach (['publish', 'invalidate'] as $operation) {
            try {
                if ('publish' === $operation) {
                    $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10Z'));
                } else {
                    $this->publisher()->invalidate(self::LISTING, new \DateTimeImmutable('2026-10-10Z'), 'withdrawn');
                }
                self::fail('Availability must share the seller transaction.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('requires an owning transaction', $error->getMessage());
            }
        }
    }

    public function testPublicationWinsTheRaceBoundaryAndALaterMembershipIsExcluded(): void
    {
        $this->seed();
        $this->database->beginTransaction();
        $offer = $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10Z'));
        $competing = \Doctrine\DBAL\DriverManager::getConnection($this->database->getParams());
        $competing->executeStatement("SET lock_timeout='100ms'");
        $waitlist = new \App\Application\Availability\ManageRaceWaitlist(new \App\Infrastructure\Availability\DbalWaitlistStore($competing), new \App\Infrastructure\Operations\DbalTransactionManager($competing), new \App\Infrastructure\Operations\DbalAuditWriter($competing), self::getContainer()->get(\App\Application\Identity\IdentityEffects::class), 'test-approved-race-email-v1', 'test-approved-race-in-app-v1');
        try {
            $waitlist->join(self::MEMBER, self::RACE, '00000000-0000-0000-0000-000000000021', true, true, new \DateTimeImmutable('2026-10-10Z'));
            self::fail('Join must wait for publication snapshot commit.');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
        }
        $this->database->commit();
        $waitlist->join(self::MEMBER, self::RACE, '00000000-0000-0000-0000-000000000021', true, true, new \DateTimeImmutable('2026-10-10Z'));
        self::assertNotNull($waitlist->mine(self::MEMBER, self::RACE));
        self::assertSame(0, $offer->recipientCount);
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM availability_recipients'));
        $competing->close();
    }

    public function testAnInvalidatedOfferCannotRestartTheSameWindow(): void
    {
        $this->seed();
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10Z'));
        $this->publisher()->invalidate(self::LISTING, new \DateTimeImmutable('2026-10-10T00:01:00Z'), 'withdrawn');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Offer version must advance.');
        try {
            $this->publisher()->publish('00000000-0000-0000-0000-000000000023', self::LISTING, new \DateTimeImmutable('2026-10-10T00:10:00Z'));
        } finally {
            $this->database->rollBack();
        }
    }

    public function testMissingDraftAndInvalidReasonRequestsCannotCreateDurableEffects(): void
    {
        $this->seed();
        $this->database->executeStatement("UPDATE resale_listings SET state='draft'");
        $transactions = new \App\Infrastructure\Operations\DbalTransactionManager($this->database);
        foreach ([self::LISTING, '00000000-0000-0000-0000-000000000099'] as $id) {
            try {
                $transactions->run(function () use ($id): void { $this->publisher()->publish(self::EVENT, $id, new \DateTimeImmutable('2026-10-10Z')); });
                self::fail('Unavailable listing must not publish.');
            } catch (\DomainException $error) {
                self::assertSame('Listing is unavailable.', $error->getMessage());
            }
        }
        try {
            $transactions->run(function (): void { $this->publisher()->invalidate('00000000-0000-0000-0000-000000000099', new \DateTimeImmutable('2026-10-10Z'), 'withdrawn'); });
            self::fail('Missing listing must not invalidate.');
        } catch (\DomainException $error) {
            self::assertSame('Listing is unavailable.', $error->getMessage());
        }
        try {
            $this->publisher()->invalidate(self::LISTING, new \DateTimeImmutable('2026-10-10Z'), 'arbitrary private text');
            self::fail('Invalid reason must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Invalid offer invalidation reason.', $error->getMessage());
        }
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
    }
}
