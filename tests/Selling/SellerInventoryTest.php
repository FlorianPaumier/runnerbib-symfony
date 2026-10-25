<?php

declare(strict_types=1);

namespace App\Tests\Selling;

use App\Application\Selling\SellerInventory;
use App\Infrastructure\Identity\SecurityCurrentIdentity;
use App\Infrastructure\Identity\SecurityUser;
use App\Infrastructure\Selling\DbalSellerInventoryStore;
use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class SellerInventoryTest extends IsolatedWebTestCase
{
    public function testAuthenticatedSellerReadsOnlyOwnTextualBibAndAttachedProofSnapshot(): void
    {
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z'");
        $items = $this->inventory('00000000-0000-0000-0000-000000000001')->list();
        self::assertCount(1, $items);
        self::assertSame([
            'entitlementId' => '00000000-0000-0000-0000-000000000006',
            'raceId' => '00000000-0000-0000-0000-000000000004',
            'raceName' => 'Paris Marathon',
            'bibNumber' => '00123',
            'ownsEntitlement' => true,
            'listingId' => '00000000-0000-0000-0000-000000000009',
            'listingVersion' => 1,
            'priceCents' => 10000,
            'listingState' => 'published',
            'proofId' => '00000000-0000-0000-0000-000000000008',
            'proofDecision' => 'approved',
            'proofVersion' => 1,
            'reviewedVersion' => 1,
            'checkoutProtected' => true,
            'approvedProofs' => [['id' => '00000000-0000-0000-0000-000000000008', 'submittedAt' => '2026-01-01T00:00:00+00:00']],
        ], get_object_vars($items[0]));
        $fees = $items[0]->fees();
        self::assertNotNull($fees);
        self::assertSame(10500, $fees->buyerTotalCents);
        self::assertSame(9250, $fees->sellerNetCents);
        self::assertSame([], $this->inventory('00000000-0000-0000-0000-000000000002')->list());
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testRealPdoStringifiedRowsFailClosedWithoutInventoryOrProviderMutation(): void
    {
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z'");
        $connection = $this->stringifiedDatabase();
        try {
            $this->inventory('00000000-0000-0000-0000-000000000001', $connection)->list();
            self::fail('Stringified inventory scalars must not bypass native row validation.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame('Invalid stored seller inventory.', $failure->getMessage());
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM resale_listings'));
            self::assertSame(10000, $this->database->fetchOne('SELECT price_cents FROM resale_listings'));
            self::assertSame('00000000-0000-0000-0000-000000000001', $this->database->fetchOne('SELECT current_owner_account_id FROM bib_entitlements'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM seller_accounts'));
        } finally {
            $connection->close();
        }
    }

    public function testUnquotableLegacyPriceDoesNotBreakInventoryRead(): void
    {
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z'");
        foreach ([1, PHP_INT_MAX] as $price) {
            $this->database->executeStatement("UPDATE resale_listings SET price_cents=? WHERE id='00000000-0000-0000-0000-000000000009'", [$price]);
            $items = $this->inventory('00000000-0000-0000-0000-000000000001')->list();
            self::assertCount(1, $items);
            self::assertSame($price, $items[0]->priceCents);
            self::assertNull($items[0]->fees());
        }
    }

    public function testCurrentUnlistedEntitlementHasNoOtherSellersListingOrPrivateFields(): void
    {
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z'");
        $this->database->executeStatement("INSERT INTO bib_entitlements(id,race_id,bib_number,source_kind,current_owner_account_id,original_holder_name,original_runner_email) VALUES ('00000000-0000-0000-0000-000000000030','00000000-0000-0000-0000-000000000005','00007','document','00000000-0000-0000-0000-000000000002','Private original holder','private-holder@example.test')");
        $items = $this->inventory('00000000-0000-0000-0000-000000000002')->list();
        self::assertCount(1, $items);
        self::assertSame('00007', $items[0]->bibNumber);
        self::assertSame('Lyon Marathon', $items[0]->raceName);
        self::assertTrue($items[0]->ownsEntitlement);
        self::assertNull($items[0]->fees());
        self::assertNull($items[0]->listingId);
        self::assertNull($items[0]->listingVersion);
        self::assertNull($items[0]->priceCents);
        self::assertNull($items[0]->listingState);
        self::assertNull($items[0]->proofId);
        self::assertFalse($items[0]->checkoutProtected);
        self::assertSame([], $items[0]->approvedProofs);
        self::assertStringNotContainsString('private-holder', json_encode($items, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Private original holder', json_encode($items, JSON_THROW_ON_ERROR));
        self::assertSame('00000000-0000-0000-0000-000000000009', $this->inventory('00000000-0000-0000-0000-000000000001')->list()[0]->listingId);
    }

    public function testAnonymousUnverifiedSuspendedAnonymizedAndUnknownAccountsCannotRead(): void
    {
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        self::assertSame([], $this->inventory(null)->list());
        self::assertSame([], $this->inventory('00000000-0000-0000-0000-000000000001')->list());
        foreach (['suspended', 'anonymized'] as $state) {
            $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z',lifecycle_state=?,anonymized_at=? WHERE id='00000000-0000-0000-0000-000000000001'", [$state, 'anonymized' === $state ? '2026-10-11Z' : null]);
            self::assertSame([], $this->inventory('00000000-0000-0000-0000-000000000001')->list());
        }
        self::assertSame([], $this->inventory('00000000-0000-0000-0000-000000000099')->list());
    }

    public function testCurrentDraftAndWithdrawnHistoryRemainSeparateAndOrdered(): void
    {
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z'");
        $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at='2026-10-11Z'");
        $store = new \App\Infrastructure\Selling\DbalListingStore($this->database, new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($store->withdraw('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000009', 1, new \DateTimeImmutable('2026-10-11Z')));
        self::assertTrue($store->draft('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030', '00000000-0000-0000-0000-000000000006', 12000));
        $items = $this->inventory('00000000-0000-0000-0000-000000000001')->list();
        self::assertCount(2, $items);
        self::assertSame(['withdrawn', 'draft'], array_map(static fn ($item) => $item->listingState, $items));
        self::assertSame([2, 1], array_map(static fn ($item) => $item->listingVersion, $items));
        self::assertSame([10000, 12000], array_map(static fn ($item) => $item->priceCents, $items));
        self::assertNull($items[1]->proofId);
        self::assertNull($items[1]->proofDecision);
        self::assertNull($items[1]->proofVersion);
        self::assertNull($items[1]->reviewedVersion);
        self::assertEquals($items, $this->inventory('00000000-0000-0000-0000-000000000001')->list());
    }

    private function inventory(?string $accountId, ?\Doctrine\DBAL\Connection $connection = null): SellerInventory
    {
        $tokens = new TokenStorage();
        if (null !== $accountId) {
            $tokens->setToken(new UsernamePasswordToken(new SecurityUser($accountId, 'seller@example.test', 'hash', ['runner'], 1), 'main', ['ROLE_USER']));
        }

        return new SellerInventory(new SecurityCurrentIdentity($tokens), new DbalSellerInventoryStore($connection ?? $this->database));
    }
}
