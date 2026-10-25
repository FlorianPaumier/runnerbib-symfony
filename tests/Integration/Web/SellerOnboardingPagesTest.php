<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Infrastructure\Identity\IdentityUserProvider;
use App\Tests\Support\IsolatedWebTestCase;

final class SellerOnboardingPagesTest extends IsolatedWebTestCase
{
    public function testMissingProviderConfigurationDoesNotCreateAnExternalIntentOrPretendReadiness(): void
    {
        $account = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $account, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-10Z', 'verified_at' => '2026-10-10Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $account, 'email' => 'seller@example.test', 'email_normalized' => 'seller@example.test']);
        $client = self::createClient();
        $client->request('GET', '/compte/vendeur');
        self::assertResponseRedirects('/connexion');
        $client->request('GET', '/compte/vendeur');
        self::assertResponseRedirects('/connexion');
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('seller@example.test'));
        $crawler = $client->request('GET', '/compte/vendeur');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Compte vendeur');
        self::assertSelectorExists('input[name="country"][required]');
        self::assertSelectorNotExists('input[name="country"][value="FR"]');
        $client->request('POST', '/compte/vendeur', ['country' => 'FR', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $form = $crawler->selectButton('Configurer mon compte vendeur')->form(['country' => 'CA']);
        $client->submit($form);
        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('[role="alert"]', 'indisponible');
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM seller_onboarding_operations'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM seller_accounts'));
    }

    public function testHostedOnboardingAndReconciliationUseTheAuthenticatedSeller(): void
    {
        $account = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $account, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-10Z', 'verified_at' => '2026-10-10Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $account, 'email' => 'seller@example.test', 'email_normalized' => 'seller@example.test']);
        $provider = new class implements \App\Application\Finance\SellerProvider {
            public \App\Domain\Finance\SellerReadiness $status = \App\Domain\Finance\SellerReadiness::Pending;

            public function configurationFingerprint(): string
            {
                return 'controlled-configuration';
            }

            public function assertConfigured(): void
            {
            }

            public function createRecipient(\App\Domain\Finance\SellerIdentity $identity, string $operationId, string $idempotencyKey): string
            {
                return 'acct_controlled_seller';
            }

            public function readiness(string $providerReference): \App\Domain\Finance\SellerReadiness
            {
                return $this->status;
            }

            public function onboardingLink(string $providerReference): string
            {
                return 'https://connect.stripe.com/setup/controlled';
            }
        };
        $previousScope = $_SERVER['STRIPE_PROVIDER_SCOPE'] ?? null;
        $_SERVER['STRIPE_PROVIDER_SCOPE'] = $_ENV['STRIPE_PROVIDER_SCOPE'] = 'controlled:test';
        try {
            $client = self::createClient();
            $client->disableReboot();
            self::getContainer()->set(\App\Application\Finance\SellerProvider::class, $provider);
            $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('seller@example.test'));
            $crawler = $client->request('GET', '/compte/vendeur');
            $client->submit($crawler->selectButton('Vérifier la configuration auprès du prestataire')->form());
            self::assertResponseStatusCodeSame(403);
            $client->submit($crawler->selectButton('Configurer mon compte vendeur')->form(['country' => 'BAD']));
            self::assertResponseStatusCodeSame(400);
            $client->submit($crawler->selectButton('Configurer mon compte vendeur')->form(['country' => 'ca']));
            self::assertResponseRedirects('https://connect.stripe.com/setup/controlled', 303);
            self::assertSame($account, $this->database->fetchOne('SELECT seller_id::text FROM seller_onboarding_operations'));
            self::assertSame('CA', $this->database->fetchOne("SELECT parameters->>'country' FROM seller_onboarding_operations"));
            $provider->status = \App\Domain\Finance\SellerReadiness::Ready;
            $crawler = $client->request('GET', '/compte/vendeur');
            $client->submit($crawler->selectButton('Vérifier la configuration auprès du prestataire')->form());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', 'recevoir les transferts');
            self::assertSelectorTextNotContains('main', 'acct_controlled_seller');
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM seller_onboarding_operations'));
        } finally {
            if (null === $previousScope) {
                unset($_SERVER['STRIPE_PROVIDER_SCOPE'], $_ENV['STRIPE_PROVIDER_SCOPE']);
            } else {
                $_SERVER['STRIPE_PROVIDER_SCOPE'] = $_ENV['STRIPE_PROVIDER_SCOPE'] = $previousScope;
            }
        }
    }
}
