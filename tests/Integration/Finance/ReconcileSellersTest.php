<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Application\Finance\ReconcileSellers;
use App\Application\Finance\SellerOnboarding;
use App\Domain\Finance\SellerReadiness;
use App\Infrastructure\Finance\DbalSellerOnboardingStore;
use App\Tests\Support\IsolatedWebTestCase;

final class ReconcileSellersTest extends IsolatedWebTestCase
{
    private const SELLER = '00000000-0000-0000-0000-000000000001';

    public function testBlockedSellerIsReconfirmedAndBecomesReadyAgain(): void
    {
        $this->seller('pending');
        $provider = new ControlledSellerProvider();
        $provider->status = SellerReadiness::Ready;
        $result = $this->sellers($provider)->reconcileDue(new \DateTimeImmutable('2026-10-10T12:00:00Z'), 50);
        self::assertSame(['reconciled' => 1, 'failed' => 0], $result);
        self::assertSame('ready', $this->database->fetchOne('SELECT readiness_state FROM seller_accounts'));
    }

    public function testFailedConfirmationKeepsSellerBlockedAndLaterCycleRecoversIt(): void
    {
        $this->seller('pending');
        $provider = new ControlledSellerProvider();
        $provider->failReadiness = true;
        $result = $this->sellers($provider)->reconcileDue(new \DateTimeImmutable('2026-10-10T12:00:00Z'), 50);
        self::assertSame(['reconciled' => 0, 'failed' => 1], $result);
        self::assertSame('pending', $this->database->fetchOne('SELECT readiness_state FROM seller_accounts'));
        $provider->failReadiness = false;
        $provider->status = SellerReadiness::Ready;
        $result = $this->sellers($provider)->reconcileDue(new \DateTimeImmutable('2026-10-10T12:10:00Z'), 50);
        self::assertSame(['reconciled' => 1, 'failed' => 0], $result);
        self::assertSame('ready', $this->database->fetchOne('SELECT readiness_state FROM seller_accounts'));
    }

    public function testConfirmedReadySellerIsNotReconfirmed(): void
    {
        $this->seller('ready');
        $provider = new ControlledSellerProvider();
        $provider->status = SellerReadiness::Restricted;
        $result = $this->sellers($provider)->reconcileDue(new \DateTimeImmutable('2026-10-10T12:00:00Z'), 50);
        self::assertSame(['reconciled' => 0, 'failed' => 0], $result);
        self::assertSame('ready', $this->database->fetchOne('SELECT readiness_state FROM seller_accounts'));
    }

    public function testAnotherProviderScopeIsNeverReconfirmed(): void
    {
        $this->seller('pending');
        $provider = new ControlledSellerProvider();
        $result = $this->sellers($provider, 'other:platform')->reconcileDue(new \DateTimeImmutable('2026-10-10T12:00:00Z'), 50);
        self::assertSame(['reconciled' => 0, 'failed' => 0], $result);
        self::assertSame('pending', $this->database->fetchOne('SELECT readiness_state FROM seller_accounts'));
    }

    public function testInvalidLimitIsRefused(): void
    {
        $this->seller('pending');
        $provider = new ControlledSellerProvider();
        $this->expectException(\InvalidArgumentException::class);
        $this->sellers($provider)->reconcileDue(new \DateTimeImmutable('2026-10-10T12:00:00Z'), 0);
    }

    public function testReadinessIsReconciledOnlyAfterTheFiveMinuteFreshnessBoundary(): void
    {
        $this->seller('pending');
        $this->database->executeStatement("UPDATE seller_accounts SET last_reconciled_at='2026-10-10T11:55:00Z'");
        $provider = new ControlledSellerProvider();
        $provider->status = SellerReadiness::Ready;
        self::assertSame(['reconciled' => 0, 'failed' => 0], $this->sellers($provider)->reconcileDue(new \DateTimeImmutable('2026-10-10T12:00:00Z'), 1));
        self::assertSame(['reconciled' => 1, 'failed' => 0], $this->sellers($provider)->reconcileDue(new \DateTimeImmutable('2026-10-10T12:00:01Z'), 1000));
    }

    private function sellers(ControlledSellerProvider $provider, string $scope = 'test:platform'): ReconcileSellers
    {
        $store = new DbalSellerOnboardingStore($this->database);

        return new ReconcileSellers($store, new SellerOnboarding($store, $provider, $scope), $scope);
    }

    private function seller(string $readiness): void
    {
        $this->database->insert('accounts', ['id' => self::SELLER, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => self::SELLER, 'email' => 'seller@example.test', 'email_normalized' => 'seller@example.test']);
        $this->database->insert('seller_accounts', ['id' => '00000000-0000-0000-0000-000000000050', 'seller_id' => self::SELLER, 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'connected_account_ref' => 'acct_recipient', 'readiness_state' => $readiness, 'last_reconciled_at' => '2026-10-09T12:00:00Z']);
    }
}
