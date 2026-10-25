<?php

declare(strict_types=1);

namespace App\Tests\Selling;

use App\Application\Selling\ManageListings;
use App\Infrastructure\Identity\SecurityCurrentIdentity;
use App\Infrastructure\Selling\DbalListingStore;
use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class ListingAccessTest extends IsolatedWebTestCase
{
    public function testAnonymousActorCannotMutateListings(): void
    {
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        $listings = new ManageListings(new SecurityCurrentIdentity(new TokenStorage()), $store, new \App\Tests\Integration\Finance\ControlledSellerProvider());
        $now = new \DateTimeImmutable('2026-10-10Z');
        self::assertFalse($listings->attachProof('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', 1));
        self::assertFalse($listings->draft('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', 10000));
        self::assertFalse($listings->publish('00000000-0000-0000-0000-000000000001', 1, $now));
        self::assertFalse($listings->reprice('00000000-0000-0000-0000-000000000001', 12000, 1, $now));
        self::assertFalse($listings->withdraw('00000000-0000-0000-0000-000000000001', 1, $now));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM resale_listings'));
    }

    public function testAuthenticatedOwnerCompletesDraftProofPublishRepriceAndWithdrawal(): void
    {
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE races SET resale_deadline='2027-03-31T12:00:00Z'");
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-10Z'");
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10Z'");
        $this->database->executeStatement("UPDATE resale_listings SET state='withdrawn',withdrawn_at='2026-10-10Z'");
        $this->database->executeStatement("INSERT INTO seller_accounts(id,seller_id,provider,provider_scope,connected_account_ref,readiness_state,last_reconciled_at) VALUES ('00000000-0000-0000-0000-000000000021','00000000-0000-0000-0000-000000000001','stripe','test:platform','acct_synthetic','ready','2026-10-10Z')");
        $tokens = new TokenStorage();
        $tokens->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new \App\Infrastructure\Identity\SecurityUser('00000000-0000-0000-0000-000000000001', 'owner@example.test', 'hash', ['runner'], 1), 'main', ['ROLE_USER']));
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        $listings = new ManageListings(new SecurityCurrentIdentity($tokens), $store, new \App\Tests\Integration\Finance\ControlledSellerProvider());
        $now = new \DateTimeImmutable('2026-10-10T12:00:00Z');
        $id = '00000000-0000-0000-0000-000000000022';
        self::assertTrue($listings->draft($id, '00000000-0000-0000-0000-000000000006', 10000));
        self::assertTrue($listings->attachProof($id, '00000000-0000-0000-0000-000000000008', 1));
        self::assertTrue($listings->publish($id, 2, $now));
        self::assertTrue($listings->reprice($id, 12000, 3, $now->modify('+1 minute')));
        self::assertTrue($listings->withdraw($id, 4, $now->modify('+2 minutes')));
        self::assertSame(5, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE subject_kind='listing'"));
        self::assertSame('withdrawn', $this->database->fetchOne('SELECT state FROM resale_listings WHERE id=?', [$id]));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM availability_events WHERE listing_id=? AND invalidated_at IS NULL', [$id]));
    }

    public function testUnconfiguredProviderBlocksPublicationBeforeAnyDatabaseMutation(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new \App\Infrastructure\Identity\SecurityUser('00000000-0000-0000-0000-000000000001', 'owner@example.test', 'hash', ['runner'], 1), 'main', ['ROLE_USER']));
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        $listings = new ManageListings(new SecurityCurrentIdentity($tokens), $store, new \App\Infrastructure\Finance\UnconfiguredSellerProvider());
        $this->expectException(\App\Application\Finance\SellerProviderUnavailable::class);
        $listings->publish('00000000-0000-0000-0000-000000000022', 1, new \DateTimeImmutable('2026-10-10T12:00:00Z'));
    }

    public function testUnconfiguredProviderCannotReuseCachedReadySellerForRepricing(): void
    {
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE races SET resale_deadline='2027-03-31T12:00:00Z'");
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-10Z'");
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-10Z'");
        $this->database->executeStatement("INSERT INTO seller_accounts(id,seller_id,provider,provider_scope,connected_account_ref,readiness_state,last_reconciled_at) VALUES ('00000000-0000-0000-0000-000000000021','00000000-0000-0000-0000-000000000001','stripe','test:platform','acct_synthetic','ready','2026-10-10Z')");
        $tokens = new TokenStorage();
        $tokens->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new \App\Infrastructure\Identity\SecurityUser('00000000-0000-0000-0000-000000000001', 'owner@example.test', 'hash', ['runner'], 1), 'main', ['ROLE_USER']));
        $store = new DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        $listings = new ManageListings(new SecurityCurrentIdentity($tokens), $store, new \App\Infrastructure\Finance\UnconfiguredSellerProvider());
        try {
            $listings->reprice('00000000-0000-0000-0000-000000000009', 12000, 1, new \DateTimeImmutable('2026-10-10T12:00:00Z'));
            self::fail('Operator configuration must be checked before repricing.');
        } catch (\App\Application\Finance\SellerProviderUnavailable) {
        }
        self::assertSame(10000, $this->database->fetchOne("SELECT price_cents FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000009'"));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
    }
}
