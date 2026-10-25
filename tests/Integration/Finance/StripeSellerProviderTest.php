<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Domain\Finance\SellerIdentity;
use App\Infrastructure\Finance\StripeSellerConfiguration;
use App\Infrastructure\Finance\StripeSellerProvider;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

final class StripeSellerProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());
        parent::tearDown();
    }

    public function testCreatesOnlyRecipientTransferAccountForActualIndividualAndCountry(): void
    {
        $http = new ControlledStripeHttpClient();
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        self::assertSame('acct_controlled', $provider->createRecipient(new SellerIdentity('DE', 'verified@example.test'), 'operation-1', 'key-1'));
        self::assertSame('https://api.stripe.com/v2/core/accounts', $http->requests[0]['url']);
        $parameters = $http->requests[0]['params'];
        self::assertIsArray($parameters);
        self::assertSame(['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]], $parameters['configuration']);
        self::assertSame(['country' => 'DE', 'entity_type' => 'individual'], $parameters['identity']);
        self::assertSame('verified@example.test', $parameters['contact_email']);
        self::assertSame('express', $parameters['dashboard']);
        self::assertIsArray($parameters['defaults']);
        self::assertSame(['fees_collector' => 'application', 'losses_collector' => 'application'], $parameters['defaults']['responsibilities']);
        self::assertSame(['seller_onboarding_operation' => 'operation-1'], $parameters['metadata']);
        self::assertSame('https://api.stripe.com/v1/balance_settings', $http->requests[1]['url']);
        self::assertSame(['payments' => ['payouts' => ['schedule' => ['interval' => 'daily']]]], $http->requests[1]['params']);
    }

    public function testReadinessComesFromRecipientTransferCapabilityAndRequirements(): void
    {
        $http = new ControlledStripeHttpClient();
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        self::assertSame('ready', $provider->readiness('acct_controlled')->value);
        self::assertSame('https://api.stripe.com/v2/core/accounts/acct_controlled', $http->requests[0]['url']);
        self::assertSame(['include' => ['configuration.recipient', 'requirements']], $http->requests[0]['params']);
    }

    public function testOutstandingRequirementsKeepEvenActiveRecipientPending(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account['requirements'] = ['entries' => [['description' => 'Bank ownership evidence', 'awaiting_action_from' => 'user']]];
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        self::assertSame('pending', $provider->readiness('acct_controlled')->value);
    }

    public function testClosedAccountCannotAppearReadyFromHistoricalCapability(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account['closed'] = true;
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        self::assertSame('restricted', $provider->readiness('acct_controlled')->value);
    }

    public function testMissingRequirementsCannotAuthorizeSellerReadiness(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account['requirements'] = null;
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        self::assertSame('restricted', $provider->readiness('acct_controlled')->value);
    }

    public function testHostedLinkCollectsFullRequirementsForExistingAccount(): void
    {
        $http = new ControlledStripeHttpClient();
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        self::assertSame('https://connect.stripe.com/setup/controlled', $provider->onboardingLink('acct_existing'));
        self::assertCount(1, $http->requests);
        self::assertSame('https://api.stripe.com/v2/core/account_links', $http->requests[0]['url']);
        self::assertSame(['account' => 'acct_existing', 'use_case' => ['type' => 'account_onboarding', 'account_onboarding' => ['collection_options' => ['fields' => 'eventually_due', 'future_requirements' => 'include'], 'return_url' => 'https://runner.example/return', 'refresh_url' => 'https://runner.example/refresh']]], $http->requests[0]['params']);
    }

    public function testReadinessTransportFailureHasTypedUnavailableOutcome(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->failRequests = true;
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        $this->expectException(\App\Application\Finance\SellerProviderUnavailable::class);
        $provider->readiness('acct_controlled');
    }

    public function testHostedLinkTransportFailureHasTypedUnavailableOutcome(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->failRequests = true;
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        $this->expectException(\App\Application\Finance\SellerProviderUnavailable::class);
        $provider->onboardingLink('acct_controlled');
    }

    public function testMismatchedProviderAccountCannotAuthorizeRequestedSeller(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account['id'] = 'acct_other';
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        self::assertSame('restricted', $provider->readiness('acct_controlled')->value);
    }

    public function testRecipientCreationTransportFailureHasTypedUnavailableOutcome(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->failRequests = true;
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        $this->expectException(\App\Application\Finance\SellerProviderUnavailable::class);
        $provider->createRecipient(new SellerIdentity('DE', 'verified@example.test'), 'operation-1', 'key-1');
    }

    public function testConfigurationFingerprintFreezesCreationSettingsWithoutSecretOrCallbackChanges(): void
    {
        $client = new StripeClient('sk_test_controlled');
        $provider = new StripeSellerProvider($client, new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        $sameCreation = new StripeSellerProvider($client, new StripeSellerConfiguration('rk_test_rotated', 'express', 'application', 'application', 'https://runner.example/new-return', 'https://runner.example/new-refresh'));
        $changedLiability = new StripeSellerProvider($client, new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'stripe', 'https://runner.example/return', 'https://runner.example/refresh'));
        $provider->assertConfigured();
        self::assertSame($provider->configurationFingerprint(), $sameCreation->configurationFingerprint());
        self::assertNotSame($provider->configurationFingerprint(), $changedLiability->configurationFingerprint());
        self::assertSame(64, strlen($provider->configurationFingerprint()));
    }

    public function testMissingCreatedProviderReferenceHasTypedUnavailableOutcome(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account['id'] = null;
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        $this->expectException(\App\Application\Finance\SellerProviderUnavailable::class);
        $provider->createRecipient(new SellerIdentity('DE', 'verified@example.test'), 'operation-1', 'key-1');
    }

    public function testUnsafeHostedProviderUrlHasTypedUnavailableOutcome(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->hostedUrl = 'http://connect.stripe.com/insecure';
        ApiRequestor::setHttpClient($http);
        $provider = new StripeSellerProvider(new StripeClient('sk_test_controlled'), new StripeSellerConfiguration('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh'));
        $this->expectException(\App\Application\Finance\SellerProviderUnavailable::class);
        $provider->onboardingLink('acct_controlled');
    }
}
