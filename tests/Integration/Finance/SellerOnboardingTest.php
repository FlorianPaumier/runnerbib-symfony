<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Application\Finance\SellerOnboarding;
use App\Infrastructure\Finance\DbalSellerOnboardingStore;
use App\Tests\Support\IsolatedWebTestCase;

final class SellerOnboardingTest extends IsolatedWebTestCase
{
    public function testProfileAndListingReuseOneRecipientAccount(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        $first = $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
        $second = $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:01:00Z'));
        self::assertSame('acct_recipient', $first->providerReference);
        self::assertSame('acct_recipient', $second->providerReference);
        self::assertSame(1, $provider->accountsCreated);
        self::assertSame('verified@example.test', $provider->submittedEmail);
        self::assertSame('pending', $first->readiness);
        self::assertSame('https://connect.stripe.test/onboarding/2', $second->onboardingUrl);
        self::assertSame('1', $this->database->fetchOne('SELECT count(*)::text FROM seller_accounts'));
        self::assertSame('succeeded', $this->database->fetchOne('SELECT state FROM seller_onboarding_operations'));
    }

    public function testSuspendedSellerCannotCreateRecipientEvenWithPrivateProfile(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'lifecycle_state' => 'suspended', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
            self::fail('Suspended seller created a recipient account.');
        } catch (\DomainException) {
            self::assertSame(0, $provider->accountsCreated);
            self::assertSame('0', $this->database->fetchOne('SELECT count(*)::text FROM seller_onboarding_operations'));
        }
    }

    public function testAlreadyReadySellerDoesNotRepeatHostedOnboarding(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $provider->status = \App\Domain\Finance\SellerReadiness::Ready;
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        $result = $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
        self::assertNull($result->onboardingUrl);
        self::assertSame(0, $provider->linksCreated);
        self::assertSame('ready', $result->readiness);
        self::assertSame('ready', $this->database->fetchOne('SELECT readiness_state FROM seller_accounts'));
    }

    public function testLostProviderResponseReusesDurableCreationIdentity(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $provider->failNextCreation = true;
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
            self::fail('Lost provider outcome was presented as complete.');
        } catch (\RuntimeException) {
            self::assertSame('0', $this->database->fetchOne('SELECT count(*)::text FROM seller_accounts'));
            self::assertSame('unknown', $this->database->fetchOne('SELECT state FROM seller_onboarding_operations'));
        }
        $this->database->update('account_private_profiles', ['email' => 'changed@example.test'], ['account_id' => $seller]);
        $result = $onboarding->start($seller, 'FR', new \DateTimeImmutable('2026-10-09T12:01:00Z'));
        self::assertSame('DE', $provider->submittedCountry);
        self::assertSame('verified@example.test', $provider->submittedEmail);
        self::assertSame('acct_recipient', $result->providerReference);
        self::assertSame(1, $provider->accountsCreated);
        self::assertCount(2, $provider->creationKeys);
        self::assertSame($provider->creationKeys[0], $provider->creationKeys[1]);
        self::assertSame('1', $this->database->fetchOne('SELECT count(*)::text FROM seller_onboarding_operations'));
    }

    public function testAmbiguousCreationBeyondProviderRetentionRequiresReconciliation(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $provider->failNextCreation = true;
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
        } catch (\RuntimeException) {
            self::assertSame(1, $provider->accountsCreated);
        }
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-11-09T12:00:00Z'));
            self::fail('Expired provider idempotency was blindly replayed.');
        } catch (\DomainException) {
            self::assertCount(1, $provider->creationKeys);
            self::assertSame('0', $this->database->fetchOne('SELECT count(*)::text FROM seller_accounts'));
        }
    }

    public function testBrowserReturnRechecksProviderAndLaterRestrictionRemovesReadiness(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
        self::assertSame('pending', $onboarding->reconcile($seller, new \DateTimeImmutable('2026-10-09T12:01:00Z'))->readiness);
        $provider->status = \App\Domain\Finance\SellerReadiness::Ready;
        self::assertSame('ready', $onboarding->reconcile($seller, new \DateTimeImmutable('2026-10-09T12:02:00Z'))->readiness);
        $provider->status = \App\Domain\Finance\SellerReadiness::Restricted;
        self::assertSame('restricted', $onboarding->reconcile($seller, new \DateTimeImmutable('2026-10-09T12:03:00Z'))->readiness);
        self::assertSame('restricted', $this->database->fetchOne('SELECT readiness_state FROM seller_accounts'));
        self::assertSame(1, $provider->accountsCreated);
    }

    public function testConcurrentOnboardingCannotSubmitSameCreationWhileClaimIsActive(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        $competitor = \Doctrine\DBAL\DriverManager::getConnection($this->database->getParams());
        $competing = new SellerOnboarding(new DbalSellerOnboardingStore($competitor), $provider, 'test-platform');
        $provider->onCreation = function () use ($competing, $provider, $seller): void {
            $provider->onCreation = null;
            try {
                $competing->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:01Z'));
                self::fail('Concurrent submission bypassed active provider claim.');
            } catch (\DomainException) {
                self::assertCount(1, $provider->creationKeys);
            }
        };
        try {
            self::assertSame('acct_recipient', $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'))->providerReference);
            self::assertSame('succeeded', $this->database->fetchOne('SELECT state FROM seller_onboarding_operations'));
        } finally {
            $competitor->close();
        }
    }

    public function testExpiredWorkerCannotCompleteAfterReplacementClaimSucceeded(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        $competitor = \Doctrine\DBAL\DriverManager::getConnection($this->database->getParams());
        $competing = new SellerOnboarding(new DbalSellerOnboardingStore($competitor), $provider, 'test-platform');
        $provider->onCreation = function () use ($competing, $provider, $seller): void {
            $provider->onCreation = null;
            self::assertSame('acct_recipient', $competing->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:06:00Z'))->providerReference);
        };
        try {
            try {
                $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
                self::fail('Expired worker was accepted after replacement completed.');
            } catch (\DomainException) {
                self::assertSame('succeeded', $this->database->fetchOne('SELECT state FROM seller_onboarding_operations'));
                self::assertSame('1', $this->database->fetchOne('SELECT count(*)::text FROM seller_accounts'));
                self::assertSame(1, $provider->accountsCreated);
            }
        } finally {
            $competitor->close();
        }
    }

    public function testMissingProviderConfigurationDoesNotPrepareCreationIntent(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = (new \App\Infrastructure\Finance\SellerProviderFactory())->create('', '', '', '', '', '', 'unconfigured');
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'unconfigured');
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
            self::fail('Onboarding proceeded without operator configuration.');
        } catch (\App\Application\Finance\SellerProviderUnavailable) {
            self::assertSame('0', $this->database->fetchOne('SELECT count(*)::text FROM seller_onboarding_operations'));
        }
    }

    public function testProviderOutageCannotLeavePreviouslyReadySellerAuthorized(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $provider->status = \App\Domain\Finance\SellerReadiness::Ready;
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
        $provider->failReadiness = true;
        try {
            $onboarding->reconcile($seller, new \DateTimeImmutable('2026-10-09T12:01:00Z'));
            self::fail('Provider outage was presented as ready.');
        } catch (\App\Application\Finance\SellerProviderUnavailable) {
            self::assertSame('pending', $this->database->fetchOne('SELECT readiness_state FROM seller_accounts'));
        }
    }

    public function testChangedProviderConfigurationCannotReplayAnAmbiguousCreation(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $provider->failNextCreation = true;
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
        } catch (\RuntimeException) {
            self::assertSame(1, $provider->accountsCreated);
        }
        $provider->configurationFingerprint = 'changed-liability-configuration';
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:01:00Z'));
            self::fail('Ambiguous creation was replayed with changed provider configuration.');
        } catch (\DomainException) {
            self::assertCount(1, $provider->creationKeys);
        }
    }

    public function testReconciliationRequiresActiveVerifiedAccountAndExistingRecipient(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), new ControlledSellerProvider(), 'test-platform');
        try {
            $onboarding->reconcile($seller, new \DateTimeImmutable('2026-10-09Z'));
            self::fail('Missing seller recipient was authorized.');
        } catch (\DomainException) {
            self::assertSame('0', $this->database->fetchOne('SELECT count(*)::text FROM seller_accounts'));
        }
        $this->database->update('accounts', ['lifecycle_state' => 'suspended'], ['id' => $seller]);
        $this->expectException(\DomainException::class);
        $onboarding->reconcile($seller, new \DateTimeImmutable('2026-10-09Z'));
    }

    public function testRedactedAmbiguousIdentityCannotBeRestoredFromChangedProfile(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $provider = new ControlledSellerProvider();
        $provider->failNextCreation = true;
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
        } catch (\RuntimeException) {
            self::assertSame(1, $provider->accountsCreated);
        }
        $this->database->executeStatement("UPDATE seller_onboarding_operations SET private_payload=NULL, private_redacted_at='2026-10-09T12:01:00Z'");
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:02:00Z'));
            self::fail('Redacted private provider identity was recreated.');
        } catch (\DomainException) {
            self::assertCount(1, $provider->creationKeys);
        }
    }

    public function testMalformedStoredProviderIdentityCannotBeSubmitted(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $this->database->insert('seller_onboarding_operations', ['id' => '00000000-0000-0000-0000-000000000002', 'seller_id' => $seller, 'provider' => 'stripe', 'provider_scope' => 'test-platform', 'idempotency_key' => 'invalid-identity', 'parameters' => '{"country":42,"provider_configuration":"controlled-configuration-v1"}', 'parameters_hash' => str_repeat('a', 64), 'private_payload' => '{"email":"verified@example.test"}', 'state' => 'prepared', 'created_at' => '2026-10-09T12:00:00Z']);
        $provider = new ControlledSellerProvider();
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:01:00Z'));
            self::fail('Malformed stored country reached provider.');
        } catch (\UnexpectedValueException) {
            self::assertSame(0, $provider->accountsCreated);
        }
    }

    public function testUnavailableDatabaseIdentityGenerationDoesNotCallProvider(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'verified_at' => '2026-10-09Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'verified@example.test', 'email_normalized' => 'verified@example.test']);
        $this->database->executeStatement('CREATE FUNCTION public.gen_random_uuid() RETURNS uuid LANGUAGE sql AS $$ SELECT NULL::uuid $$');
        $this->database->executeStatement('SET search_path=public,pg_catalog');
        $provider = new ControlledSellerProvider();
        $onboarding = new SellerOnboarding(new DbalSellerOnboardingStore($this->database), $provider, 'test-platform');
        try {
            $onboarding->start($seller, 'DE', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
            self::fail('Provider was called without durable generated identity.');
        } catch (\RuntimeException) {
            self::assertSame(0, $provider->accountsCreated);
            self::assertSame('0', $this->database->fetchOne('SELECT count(*)::text FROM seller_onboarding_operations'));
        }
    }
}
