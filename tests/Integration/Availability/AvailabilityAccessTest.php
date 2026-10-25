<?php

declare(strict_types=1);

namespace App\Tests\Integration\Availability;

use App\Application\Availability\FindOfferAccess;
use App\Infrastructure\Availability\DbalAvailabilityAccessStore;

final class AvailabilityAccessTest extends AvailabilityTestCase
{
    public function testOnlySnapshotMembersCanInitiateDuringPriorityAndThePersistedDeadlineOpensPublicAccess(): void
    {
        $this->seed();
        $this->database->executeStatement("INSERT INTO race_waitlist_memberships(id,race_id,account_id,joined_at) VALUES (?, ?,?,'2026-10-10Z')", ['00000000-0000-0000-0000-000000000021', self::RACE, self::MEMBER]);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $query = $this->query();
        $member = $query->forListing(self::LISTING, self::MEMBER, new \DateTimeImmutable('2026-10-10T10:59:59Z'));
        self::assertNotNull($member);
        self::assertSame('priority', $member->accessState);
        self::assertTrue($member->isSnapshotMember);
        self::assertTrue($member->canInitiatePurchase);
        $outsider = $query->forEvent(self::EVENT, self::LATE, new \DateTimeImmutable('2026-10-10T10:59:59Z'));
        self::assertNotNull($outsider);
        self::assertFalse($outsider->canInitiatePurchase);
        self::assertSame('2026-10-10T11:00:00+00:00', $outsider->priorityEndsAt->format(DATE_ATOM));
        $public = $query->forEvent(self::EVENT, self::LATE, new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertNotNull($public);
        self::assertSame('public', $public->accessState);
        self::assertTrue($public->canInitiatePurchase);
    }

    public function testEmptySnapshotIsImmediatelyPublicAndAnonymousUsersCanSeeTheDeadline(): void
    {
        $this->seed();
        $this->publishAt('2026-10-10T10:00:00Z');
        $guest = $this->query()->forListing(self::LISTING, null, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        self::assertNotNull($guest);
        self::assertSame('public', $guest->accessState);
        self::assertFalse($guest->canInitiatePurchase);
        self::assertSame('2026-10-10T11:00:00+00:00', $guest->priorityEndsAt->format(DATE_ATOM));
        self::assertTrue($this->query()->forListing(self::LISTING, self::LATE, new \DateTimeImmutable('2026-10-10T10:00:00Z'))?->canInitiatePurchase);
        self::assertFalse($this->query()->forListing(self::LISTING, self::LATE, new \DateTimeImmutable('2026-10-10T09:59:59Z'))?->canInitiatePurchase);
        self::assertNull($this->query()->forListing('00000000-0000-0000-0000-000000000099', self::LATE, new \DateTimeImmutable('2026-10-10Z')));
        self::assertNull($this->query()->forEvent('00000000-0000-0000-0000-000000000099', self::LATE, new \DateTimeImmutable('2026-10-10Z')));
    }

    public function testLaterMembershipAndLeavingDoNotRewriteTheOriginalSnapshot(): void
    {
        $this->seed();
        $waitlist = new \App\Application\Availability\ManageRaceWaitlist(new \App\Infrastructure\Availability\DbalWaitlistStore($this->database), new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database), self::getContainer()->get(\App\Application\Identity\IdentityEffects::class), 'test-approved-race-email-v1', 'test-approved-race-in-app-v1');
        $waitlist->join(self::MEMBER, self::RACE, '00000000-0000-0000-0000-000000000021', true, true, new \DateTimeImmutable('2026-10-10Z'));
        $this->publishAt('2026-10-10T10:00:00Z');
        $waitlist->join(self::LATE, self::RACE, '00000000-0000-0000-0000-000000000022', true, true, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $waitlist->leave(self::MEMBER, '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-10T10:02:00Z'));
        self::assertTrue($this->query()->forEvent(self::EVENT, self::MEMBER, new \DateTimeImmutable('2026-10-10T10:03:00Z'))?->canInitiatePurchase);
        self::assertFalse($this->query()->forEvent(self::EVENT, self::LATE, new \DateTimeImmutable('2026-10-10T10:03:00Z'))?->canInitiatePurchase);
        $this->database->executeStatement("UPDATE outbox_messages SET next_attempt_at='2026-10-10T12:00:00Z'");
        self::assertTrue($this->query()->forEvent(self::EVENT, self::LATE, new \DateTimeImmutable('2026-10-10T11:00:00Z'))?->canInitiatePurchase);
    }

    public function testStaleRepricedWithdrawnAndSoldOffersNeverReopenAtExpiry(): void
    {
        $this->seed();
        $this->publishAt('2026-10-10T10:00:00Z');
        $this->database->beginTransaction();
        $this->database->executeStatement('UPDATE resale_listings SET price_cents=9000,offer_version=2 WHERE id=?', [self::LISTING]);
        $this->publisher()->publish('00000000-0000-0000-0000-000000000024', self::LISTING, new \DateTimeImmutable('2026-10-10T10:05:00Z'));
        $this->database->commit();
        $old = $this->query()->forEvent(self::EVENT, self::LATE, new \DateTimeImmutable('2026-10-10T12:00:00Z'));
        self::assertNotNull($old);
        self::assertSame('unavailable', $old->accessState);
        self::assertFalse($old->canInitiatePurchase);
        $current = $this->query()->forListing(self::LISTING, self::LATE, new \DateTimeImmutable('2026-10-10T12:00:00Z'));
        self::assertNotNull($current);
        self::assertSame(9000, $current->priceCents);
        self::assertTrue($current->canInitiatePurchase);
        foreach (["state='withdrawn',withdrawn_at='2026-10-10T12:00:00Z'", "state='sold',sold_at='2026-10-10T12:00:00Z'"] as $state) {
            $this->database->executeStatement('UPDATE resale_listings SET '.$state.' WHERE id=?', [self::LISTING]);
            self::assertFalse($this->query()->forListing(self::LISTING, self::LATE, new \DateTimeImmutable('2026-10-10T13:00:00Z'))?->canInitiatePurchase);
        }
    }

    public function testSuspendedUnverifiedAndAlreadyOwningBuyersCannotInitiatePurchase(): void
    {
        $this->seed();
        $this->publishAt('2026-10-10T10:00:00Z');
        $now = new \DateTimeImmutable('2026-10-10T10:00:00Z');
        self::assertFalse($this->query()->forListing(self::LISTING, '00000000-0000-0000-0000-000000000001', $now)?->canInitiatePurchase);
        foreach (["lifecycle_state='suspended'", "lifecycle_state='active',verified_at=NULL"] as $state) {
            $this->database->executeStatement('UPDATE accounts SET '.$state.' WHERE id=?', [self::MEMBER]);
            self::assertFalse($this->query()->forListing(self::LISTING, self::MEMBER, $now)?->canInitiatePurchase);
        }
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-10Z' WHERE id=?", [self::MEMBER]);
        $this->database->executeStatement("INSERT INTO bib_entitlements(id,race_id,bib_number,source_kind,current_owner_account_id) VALUES (?,?, '999','document',?)", ['00000000-0000-0000-0000-000000000030', self::RACE, self::MEMBER]);
        self::assertFalse($this->query()->forListing(self::LISTING, self::MEMBER, $now)->canInitiatePurchase);
        self::assertTrue($this->query()->forListing(self::LISTING, self::LATE, $now)?->canInitiatePurchase);
    }

    public function testAProofAwaitingRevalidationMakesTheOfferUnavailable(): void
    {
        $this->seed();
        $this->publishAt('2026-10-10T10:00:00Z');
        $this->database->executeStatement('UPDATE ownership_proofs SET decision=NULL,reviewed_version=NULL,reviewer_id=NULL,reviewed_at=NULL');
        $offer = $this->query()->forListing(self::LISTING, self::MEMBER, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        self::assertNotNull($offer);
        self::assertSame('unavailable', $offer->accessState);
        self::assertFalse($offer->canInitiatePurchase);
    }

    public function testSubsecondExpiryAndRaceArchivalRemainAuthoritative(): void
    {
        $this->seed();
        $this->database->executeStatement("INSERT INTO race_waitlist_memberships(id,race_id,account_id,joined_at) VALUES (?, ?,?,'2026-10-10Z')", ['00000000-0000-0000-0000-000000000021', self::RACE, self::MEMBER]);
        $this->publishAt('2026-10-25T00:30:00.123456Z');
        self::assertFalse($this->query()->forListing(self::LISTING, self::LATE, new \DateTimeImmutable('2026-10-25T01:30:00.123455Z'))?->canInitiatePurchase);
        self::assertTrue($this->query()->forListing(self::LISTING, self::LATE, new \DateTimeImmutable('2026-10-25T01:30:00.123456Z'))?->canInitiatePurchase);
        $this->database->executeStatement("UPDATE races SET publication_state='archived'");
        $offer = $this->query()->forEvent(self::EVENT, self::LATE, new \DateTimeImmutable('2026-10-25T01:30:00.123456Z'));
        self::assertNotNull($offer);
        self::assertSame('unavailable', $offer->accessState);
        self::assertFalse($offer->canInitiatePurchase);
    }

    public function testMissingAndExpiredRaceResaleDeadlinesFailClosedEvenForExistingOffers(): void
    {
        $this->seed();
        $this->publishAt('2026-10-10T10:00:00Z');
        foreach ([null, '2026-10-10T10:00:00Z'] as $deadline) {
            $this->database->executeStatement('UPDATE races SET resale_deadline=? WHERE id=?', [$deadline, self::RACE]);
            $offer = $this->query()->forListing(self::LISTING, self::LATE, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
            self::assertNotNull($offer);
            self::assertSame('unavailable', $offer->accessState);
            self::assertFalse($offer->canInitiatePurchase);
        }
    }

    public function testPersistedCheckoutProtectionAndBuyerRaceGuardCannotExpireByClockAlone(): void
    {
        $this->seed();
        $this->publishAt('2026-10-10T10:00:00Z');
        $this->database->executeStatement("INSERT INTO checkouts(id,buyer_id,state,authorized_at,initial_deadline,expected_total_cents,currency) VALUES (?,?,'unknown','2026-10-10T10:00:00Z','2026-10-10T10:15:00Z',10500,'EUR')", ['00000000-0000-0000-0000-000000000040', self::MEMBER]);
        $this->database->executeStatement("INSERT INTO checkout_items(id,checkout_id,race_id,entitlement_id,listing_id,seller_id,availability_event_id,offer_version,price_cents,buyer_fee_cents,seller_margin_cents,buyer_total_cents,seller_net_cents,currency,fee_policy_version,buyer_name,buyer_email,registration_fields,participation_snapshot,acknowledgment_text_version,acknowledgment_accepted_at,initial_expires_at,reconciliation_state) VALUES (?, ?,?, '00000000-0000-0000-0000-000000000006',?,'00000000-0000-0000-0000-000000000001',?,1,10000,500,750,10500,9250,'EUR','v1','Buyer','buyer@example.test','{}','nonparticipating','v1','2026-10-10Z','2026-10-10T10:15:00Z','unknown')", ['00000000-0000-0000-0000-000000000041', '00000000-0000-0000-0000-000000000040', self::RACE, self::LISTING, self::EVENT]);
        $this->database->executeStatement('INSERT INTO buyer_race_guards(buyer_id,race_id,reserved_checkout_id) VALUES (?,?,?)', [self::MEMBER, self::RACE, '00000000-0000-0000-0000-000000000040']);
        $now = new \DateTimeImmutable('2026-10-10T12:00:00Z');
        self::assertFalse($this->query()->forListing(self::LISTING, self::LATE, $now)?->canInitiatePurchase);
        self::assertFalse($this->query()->forListing(self::LISTING, self::MEMBER, $now)?->canInitiatePurchase);
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10T12:00:00Z',reconciliation_state='resolved'");
        self::assertFalse($this->query()->forListing(self::LISTING, self::MEMBER, $now)?->canInitiatePurchase);
        self::assertTrue($this->query()->forListing(self::LISTING, self::LATE, $now)?->canInitiatePurchase);
        $this->database->executeStatement('UPDATE buyer_race_guards SET reserved_checkout_id=NULL');
        self::assertTrue($this->query()->forListing(self::LISTING, self::MEMBER, $now)?->canInitiatePurchase);
    }

    public function testUnknownRecipientHasNoAccessFacts(): void
    {
        $this->seed();
        $now = new \DateTimeImmutable('2026-10-10Z');
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, $now);
        $this->database->commit();
        $facts = (new DbalAvailabilityAccessStore($this->database))->forEventRecipients(self::EVENT, ['00000000-0000-0000-0000-0000000000ff']);
        self::assertSame([], $facts);
    }

    public function testStringifiedDriverCannotAuthorizePurchaseFromAccessFacts(): void
    {
        $this->seed();
        $now = new \DateTimeImmutable('2026-10-10Z');
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, $now);
        $this->database->commit();
        self::assertTrue($this->query()->forListing(self::LISTING, self::MEMBER, $now)?->canInitiatePurchase);
        $database = $this->stringifiedDatabase();
        try {
            (new FindOfferAccess(new DbalAvailabilityAccessStore($database)))->forListing(self::LISTING, self::MEMBER, $now);
            self::fail('Unexpected scalar types must not authorize a purchase.');
        } catch (\UnexpectedValueException $error) {
            self::assertSame('Invalid stored access facts.', $error->getMessage());
        } finally {
            $database->close();
        }
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM payment_attempts'));
    }

    private function publishAt(string $instant): void
    {
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable($instant));
        $this->database->commit();
    }

    private function query(): FindOfferAccess
    {
        return new FindOfferAccess(new DbalAvailabilityAccessStore($this->database));
    }
}
