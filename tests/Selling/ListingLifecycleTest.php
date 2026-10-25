<?php

declare(strict_types=1);

namespace App\Tests\Selling;

use App\Infrastructure\Selling\DbalListingStore;
use App\Tests\Support\IsolatedWebTestCase;

final class ListingLifecycleTest extends IsolatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE races SET resale_deadline='2027-03-31T12:00:00Z'");
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-10Z'");
        $this->database->executeStatement("INSERT INTO seller_accounts(id,seller_id,provider,provider_scope,connected_account_ref,readiness_state,last_reconciled_at) VALUES ('00000000-0000-0000-0000-000000000021','00000000-0000-0000-0000-000000000001','stripe','test:platform','acct_synthetic','ready','2026-10-10Z')");
    }

    public function testLockedListingCannotBeRepricedOrForcedUnlocked(): void
    {
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertFalse($store->reprice('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000009', 12000, 1, new \DateTimeImmutable('2026-10-10Z')));
        self::assertSame(10000, $this->database->fetchOne("SELECT price_cents FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000009'"));
        self::assertSame('protected', $this->database->fetchOne("SELECT protection_state FROM checkout_items WHERE id='00000000-0000-0000-0000-000000000012'"));
    }

    public function testNewListingCannotBypassTheSameEntitlementsProtectedCheckout(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $draft = '00000000-0000-0000-0000-000000000022';
        $now = new \DateTimeImmutable('2026-10-13Z');
        $this->database->executeStatement("UPDATE checkouts SET state='processing'");
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($store->withdraw($seller, '00000000-0000-0000-0000-000000000009', 1, $now));
        self::assertTrue($store->draft($seller, $draft, '00000000-0000-0000-0000-000000000006', 10000));
        self::assertTrue($store->attachProof($seller, $draft, '00000000-0000-0000-0000-000000000008', 1));
        self::assertFalse($store->publish($seller, $draft, 2, $now));
        self::assertSame(['state' => 'draft', 'version' => 2], $this->database->fetchAssociative('SELECT state,version FROM resale_listings WHERE id=?', [$draft]));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame('processing', $this->database->fetchOne('SELECT state FROM checkouts'));
        self::assertSame('unknown', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='checkout.cancellation_requested'"));
        self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='listing.published'"));
        self::assertSame([true, true], array_map(static fn (\App\Application\Selling\SellerInventoryItem $item): bool => $item->checkoutProtected, (new \App\Infrastructure\Selling\DbalSellerInventoryStore($this->database))->forSeller($seller)));
    }

    public function testUnlockedRepricePreservesVersionAndStartsFreshAvailability(): void
    {
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10Z' WHERE id='00000000-0000-0000-0000-000000000012'");
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($store->reprice('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000009', 12000, 1, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertSame(12000, $this->database->fetchOne("SELECT price_cents FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000009'"));
        self::assertSame(2, $this->database->fetchOne("SELECT offer_version FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000009'"));
        self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
    }

    public function testReadinessFromAnotherProviderScopeCannotAuthorizePublication(): void
    {
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10Z'");
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'live:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertFalse($store->reprice('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000009', 12000, 1, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertSame(10000, $this->database->fetchOne("SELECT price_cents FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000009'"));
    }

    public function testCurrentOwnerCanPublishDraftWithApprovedProofAndConfirmedReadiness(): void
    {
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10Z'");
        $this->database->executeStatement("UPDATE resale_listings SET state='withdrawn',withdrawn_at='2026-10-10Z' WHERE id='00000000-0000-0000-0000-000000000009'");
        $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,entitlement_id,seller_id,proof_id,approved_proof_version,price_cents) VALUES ('00000000-0000-0000-0000-000000000022','00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000006','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000008',1,10000)");
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertFalse($store->publish('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000022', 1, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertTrue($store->publish('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000022', 1, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertFalse($store->publish('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000022', 1, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertSame('published', $this->database->fetchOne("SELECT state FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000022'"));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM availability_events WHERE listing_id='00000000-0000-0000-0000-000000000022'"));
    }

    public function testWithdrawalInvalidatesOfferAndCannotReactivateHistoricalListing(): void
    {
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        $now = new \DateTimeImmutable('2026-10-10T12:00:00Z');
        self::assertFalse($store->withdraw('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000009', 1, $now));
        self::assertTrue($store->withdraw('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000009', 1, $now));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertFalse($store->withdraw('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000009', 2, $now));
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10Z'");
        self::assertSame('withdrawn', $this->database->fetchOne("SELECT state FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000009'"));
        self::assertSame('withdrawn', $this->database->fetchOne("SELECT invalidation_reason FROM availability_events WHERE id='00000000-0000-0000-0000-000000000010'"));
        self::assertFalse($store->publish('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000009', 2, $now));
    }

    public function testDraftSurvivesPendingSellerOnboardingWithoutPublishing(): void
    {
        $this->database->executeStatement("UPDATE seller_accounts SET readiness_state='pending'");
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertFalse($store->draft('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000023', '00000000-0000-0000-0000-000000000006', 10000));
        self::assertTrue($store->draft('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000023', '00000000-0000-0000-0000-000000000006', 10000));
        self::assertFalse($store->publish('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000023', 1, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertSame('draft', $this->database->fetchOne("SELECT state FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000023'"));
        self::assertNull($this->database->fetchOne("SELECT published_at FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000023'"));
    }

    public function testDraftProofAttachmentPinsApprovedVersionBeforePublication(): void
    {
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10Z'");
        $this->database->executeStatement("UPDATE resale_listings SET state='withdrawn',withdrawn_at='2026-10-10Z' WHERE id='00000000-0000-0000-0000-000000000009'");
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($store->draft('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000024', '00000000-0000-0000-0000-000000000006', 10000));
        self::assertFalse($store->attachProof('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000024', '00000000-0000-0000-0000-000000000008', 1));
        self::assertTrue($store->attachProof('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000024', '00000000-0000-0000-0000-000000000008', 1));
        self::assertFalse($store->publish('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000024', 1, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertTrue($store->publish('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000024', 2, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertSame(1, $this->database->fetchOne("SELECT approved_proof_version FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000024'"));
    }

    public function testConcurrentPublicationForSameEntitlementHasExactlyOneWinner(): void
    {
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10Z'");
        $this->database->executeStatement("UPDATE resale_listings SET state='withdrawn',withdrawn_at='2026-10-10Z' WHERE id='00000000-0000-0000-0000-000000000009'");
        foreach (['25', '26'] as $suffix) {
            $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,entitlement_id,seller_id,proof_id,approved_proof_version,price_cents) VALUES (?,'00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000006','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000008',1,10000)", ['00000000-0000-0000-0000-0000000000'.$suffix]);
        }
        $code = <<<'PHP'
require 'vendor/autoload.php';
$db=Doctrine\DBAL\DriverManager::getConnection(json_decode(getenv('LISTING_TEST_PARAMS'),true,512,JSON_THROW_ON_ERROR));
$db->executeStatement("SELECT set_config('application_name',?,false)",[getenv('LISTING_TEST_ID')]);
$store=new App\Infrastructure\Selling\DbalListingStore($db,new App\Application\Availability\CaptureAvailability(new App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($db),new App\Infrastructure\Operations\DbalOutboxWriter($db)),'test:platform',new App\Infrastructure\Operations\DbalAuditWriter($db),new App\Infrastructure\Operations\DbalOutboxWriter($db));
echo $store->publish('00000000-0000-0000-0000-000000000001',getenv('LISTING_TEST_ID'),1,new DateTimeImmutable('2026-10-10T12:00:00Z'))?'winner':'denied';
PHP;
        $this->database->beginTransaction();
        $this->database->fetchOne("SELECT id FROM races WHERE id='00000000-0000-0000-0000-000000000004' FOR UPDATE");
        $processes = [];
        foreach (['25', '26'] as $suffix) {
            $process = new \Symfony\Component\Process\Process(['php', '-r', $code], dirname(__DIR__, 2), ['LISTING_TEST_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR), 'LISTING_TEST_ID' => '00000000-0000-0000-0000-0000000000'.$suffix]);
            $process->setTimeout(15)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 5;
        do {
            $this->database->fetchOne('SELECT pg_stat_clear_snapshot()');
            $blocked = $this->database->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND application_name IN ('00000000-0000-0000-0000-000000000025','00000000-0000-0000-0000-000000000026') AND wait_event_type='Lock'");
            if (2 === $blocked) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->database->commit();
        self::assertSame(2, $blocked, 'Both publishers must contend on the held race lock.');
        $results = [];
        foreach ($processes as $process) {
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        sort($results);
        self::assertSame(['denied', 'winner'], $results);
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM resale_listings WHERE state='published'"));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='availability.batch_ready'"));
    }

    public function testArchivedRaceCannotPublishOrRepriceAnOffer(): void
    {
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10Z'");
        $this->database->executeStatement("UPDATE races SET publication_state='archived' WHERE id='00000000-0000-0000-0000-000000000004'");
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertFalse($store->reprice('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000009', 12000, 1, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertSame(10000, $this->database->fetchOne("SELECT price_cents FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000009'"));
    }

    public function testAuditFailureRollsBackRepriceSnapshotAndOutboxIntent(): void
    {
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10Z'");
        $this->database->executeStatement("CREATE FUNCTION reject_listing_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic audit failure'; END; $$");
        $this->database->executeStatement('CREATE TRIGGER reject_listing_audit BEFORE INSERT ON administrative_audit FOR EACH ROW EXECUTE FUNCTION reject_listing_audit()');
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        try {
            $store->reprice('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000009', 12000, 1, new \DateTimeImmutable('2026-10-10T12:00:00Z'));
            self::fail('Audit failure must abort the whole listing transaction.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
        }
        self::assertSame(10000, $this->database->fetchOne("SELECT price_cents FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000009'"));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
        self::assertNull($this->database->fetchOne("SELECT invalidated_at FROM availability_events WHERE id='00000000-0000-0000-0000-000000000010'"));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
    }

    public function testMissingResourcesAndUnconfiguredProviderCannotChangeAnyListing(): void
    {
        $publisher = new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        $audit = new \App\Infrastructure\Operations\DbalAuditWriter($this->database);
        $store = new DbalListingStore($this->database, $publisher, 'test:platform', $audit, new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        $seller = '00000000-0000-0000-0000-000000000001';
        $missing = '00000000-0000-0000-0000-000000000099';
        $now = new \DateTimeImmutable('2026-10-10T12:00:00Z');
        self::assertFalse($store->draft($seller, $missing, $missing, 10000));
        self::assertFalse($store->attachProof($seller, $missing, $missing, 1));
        self::assertFalse($store->publish($seller, $missing, 1, $now));
        self::assertFalse($store->withdraw($seller, $missing, 1, $now));
        self::assertFalse((new DbalListingStore($this->database, $publisher, 'unconfigured', $audit, new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)))->publish($seller, '00000000-0000-0000-0000-000000000009', 1, $now));
        self::assertFalse((new DbalListingStore($this->database, $publisher, '', $audit, new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)))->reprice($seller, '00000000-0000-0000-0000-000000000009', 12000, 1, $now));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testOwnerCanWithdrawUnpublishedDraftWithoutCreatingApprovalOrAvailability(): void
    {
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'unconfigured', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($store->draft('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000006', 10000));
        self::assertTrue($store->withdraw('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000022', 1, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertSame(['state' => 'withdrawn', 'proof_id' => null, 'published_at' => null, 'version' => 2], $this->database->fetchAssociative("SELECT state,proof_id,published_at,version FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000022'"));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertFalse($store->withdraw('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000022', 2, new \DateTimeImmutable('2026-10-10T12:00:00Z')));
    }

    public function testMissingAndUnreviewedProofCannotBeAttachedToOwnDraft(): void
    {
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($store->draft('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000006', 10000));
        self::assertFalse($store->attachProof('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000099', 1));
        $this->database->executeStatement("INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,entitlement_id,source_kind,private_object_id,submitted_at) VALUES ('00000000-0000-0000-0000-000000000023','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000004','00123','00000000-0000-0000-0000-000000000006','document','00000000-0000-0000-0000-000000000007','2026-10-10Z')");
        self::assertFalse($store->attachProof('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', 1));
        self::assertSame(['proof_id' => null, 'approved_proof_version' => null, 'version' => 1], $this->database->fetchAssociative("SELECT proof_id,approved_proof_version,version FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000022'"));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testSuspendedSellerCannotWithdrawPublishedListing(): void
    {
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='suspended' WHERE id='00000000-0000-0000-0000-000000000001'");
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertFalse($store->withdraw('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000009', 1, new \DateTimeImmutable('2026-10-10Z')));
        self::assertSame('published', $this->database->fetchOne('SELECT state FROM resale_listings'));
        self::assertNull($this->database->fetchOne('SELECT invalidated_at FROM availability_events'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }
}
