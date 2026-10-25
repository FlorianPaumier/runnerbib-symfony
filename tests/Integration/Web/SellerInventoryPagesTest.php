<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Infrastructure\Identity\IdentityUserProvider;
use App\Tests\Integration\Availability\AvailabilityTestCase;
use App\Tests\Integration\Finance\ControlledSellerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SellerInventoryPagesTest extends AvailabilityTestCase
{
    private const SELLER = '00000000-0000-0000-0000-000000000001';
    private const DRAFT = '00000000-0000-0000-0000-000000000022';
    private const PROOF = '00000000-0000-0000-0000-000000000008';

    public function testProtectedWithdrawalRequestsWholeCheckoutCancellationAndKeepsNewDraftBlocked(): void
    {
        $fixture = file_get_contents(dirname(__DIR__, 2).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-13Z'");
        $this->database->executeStatement("UPDATE checkouts SET state='processing'");
        $this->database->insert('account_private_profiles', ['account_id' => self::SELLER, 'email' => 'seller@example.test', 'email_normalized' => 'seller@example.test']);
        $client = $this->sellerClient();
        $crawler = $client->request('GET', '/compte/ventes');
        self::assertSelectorNotExists('input[name="action"][value="prix"]');
        self::assertSelectorTextContains('main', 'tout l’achat');
        self::assertSelectorTextContains('[role="status"]', 'le retrait ne débloque pas le paiement');
        $client->submit($crawler->selectButton('Retirer l’annonce')->form());
        self::assertResponseRedirects('/compte/ventes', 303);
        self::assertSame('withdrawn', $this->database->fetchOne('SELECT state FROM resale_listings'));
        self::assertSame('processing', $this->database->fetchOne('SELECT state FROM checkouts'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame('unknown', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
        self::assertNotNull($this->database->fetchOne('SELECT cancellation_requested_at FROM checkouts'));
        self::assertSame('00000000-0000-0000-0000-000000000013', $this->database->fetchOne("SELECT payload->>'operation_id' FROM outbox_messages WHERE event_type='checkout.cancellation_requested'"));
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Enregistrer le brouillon')->form(['price' => '100,00']));
        self::assertResponseRedirects('/compte/ventes', 303);
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Joindre le justificatif')->form(['proof_id' => self::PROOF]));
        self::assertResponseRedirects('/compte/ventes', 303);
        $client->followRedirect();
        self::assertSelectorExists('button[disabled]');
        self::assertSelectorTextContains('[role="status"]', 'Un paiement est en cours');
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM financial_operations'));
    }

    public function testDraftRequiresCsrfAndValidStableIdentity(): void
    {
        $this->seedDraft();
        $this->database->insert('bib_entitlements', ['id' => '00000000-0000-0000-0000-000000000030', 'race_id' => '00000000-0000-0000-0000-000000000005', 'bib_number' => '00042', 'source_kind' => 'document', 'current_owner_account_id' => self::SELLER]);
        $client = $this->sellerClient();
        $crawler = $client->request('GET', '/compte/ventes');
        $form = $crawler->selectButton('Enregistrer le brouillon')->form(['price' => '100,00']);
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['_token' => 'forged']));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['listing_id' => 'not-a-uuid']));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('100,00', $client->getCrawler()->filter('input[name="price"]')->attr('value'));
        self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM resale_listings WHERE entitlement_id='00000000-0000-0000-0000-000000000030'"));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validPrices')]
    public function testDraftAcceptsExactIntegerDotAndLeadingZeroAmounts(string $price, int $cents): void
    {
        $this->seedDraft();
        $this->database->insert('bib_entitlements', ['id' => '00000000-0000-0000-0000-000000000030', 'race_id' => '00000000-0000-0000-0000-000000000005', 'bib_number' => '00042', 'source_kind' => 'document', 'current_owner_account_id' => self::SELLER]);
        $client = $this->sellerClient();
        $crawler = $client->request('GET', '/compte/ventes');
        $client->submit($crawler->selectButton('Enregistrer le brouillon')->form(['price' => $price]));
        self::assertResponseRedirects('/compte/ventes', 303);
        self::assertSame($cents, $this->database->fetchOne("SELECT price_cents FROM resale_listings WHERE entitlement_id='00000000-0000-0000-0000-000000000030'"));
    }

    /** @return iterable<string,array{string,int}> */
    public static function validPrices(): iterable
    {
        yield 'whole euros' => ['100', 10000];
        yield 'dot decimal' => ['100.10', 10010];
        yield 'leading zeros and one decimal' => ['000100.1', 10010];
        yield 'one cent seller net' => ['5,01', 501];
    }

    public function testUnconfiguredProviderPreservesDraftAndDoesNotPretendPublication(): void
    {
        $this->seedDraft();
        $client = $this->sellerClient();
        $crawler = $client->request('GET', '/compte/ventes');
        $client->submit($crawler->selectButton('Joindre le justificatif')->form(['proof_id' => self::PROOF]));
        self::assertResponseRedirects('/compte/ventes', 303);
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Publier l’annonce')->form());
        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('[role="alert"]', 'Votre annonce est conservée');
        self::assertSame(['state' => 'draft', 'version' => 2], $this->database->fetchAssociative('SELECT state,version FROM resale_listings WHERE id=?', [self::DRAFT]));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
    }

    public function testProofAttachmentRejectsForeignPendingAndMalformedProofsWithoutMutation(): void
    {
        $this->seedDraft();
        $foreign = '00000000-0000-0000-0000-000000000031';
        $pending = '00000000-0000-0000-0000-000000000032';
        $this->database->executeStatement('INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,entitlement_id,source_kind,private_object_id,submitted_at,decision,reviewed_version,reviewer_id,reviewed_at) SELECT ?,?,race_id,proposed_bib_number,entitlement_id,source_kind,private_object_id,submitted_at,decision,reviewed_version,reviewer_id,reviewed_at FROM ownership_proofs WHERE id=?', [$foreign, self::MEMBER, self::PROOF]);
        $this->database->executeStatement('INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,entitlement_id,source_kind,private_object_id,submitted_at) SELECT ?,submitted_by,race_id,proposed_bib_number,entitlement_id,source_kind,private_object_id,submitted_at FROM ownership_proofs WHERE id=?', [$pending, self::PROOF]);
        $client = $this->sellerClient();
        $crawler = $client->request('GET', '/compte/ventes');
        self::assertSelectorCount(1, 'select[name="proof_id"] option');
        self::assertSelectorExists('option[value="'.self::PROOF.'"]');
        $form = $crawler->selectButton('Joindre le justificatif')->form();
        foreach ([$foreign => 409, $pending => 409, 'not-a-uuid' => 400] as $proof => $status) {
            $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['proof_id' => $proof]));
            self::assertResponseStatusCodeSame($status);
            self::assertSelectorExists('[role="alert"]');
            self::assertSame(['proof_id' => null, 'approved_proof_version' => null, 'version' => 1], $this->database->fetchAssociative('SELECT proof_id::text,approved_proof_version,version FROM resale_listings WHERE id=?', [self::DRAFT]));
        }
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
    }

    public function testMutationFormsRequireTheirOwnCsrfPurposeAndPositiveVersion(): void
    {
        $this->seedDraft();
        $client = $this->sellerClient();
        $crawler = $client->request('GET', '/compte/ventes');
        $form = $crawler->selectButton('Retirer l’annonce')->form();
        foreach ([['action' => 'unknown'], ['version' => '0'], ['version' => '1.5'], ['version' => '9223372036854775808']] as $values) {
            $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), $values));
            self::assertResponseStatusCodeSame(400);
        }
        foreach ([['_token' => 'forged'], ['action' => 'publier']] as $values) {
            $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), $values));
            self::assertResponseStatusCodeSame(403);
        }
        $client->request('GET', $form->getUri());
        self::assertResponseStatusCodeSame(405);
        self::assertSame(['state' => 'draft', 'version' => 1], $this->database->fetchAssociative('SELECT state,version FROM resale_listings WHERE id=?', [self::DRAFT]));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPrices')]
    public function testDraftPriceRejectsRoundingOverflowAndNonpositiveNetWithoutLosingInput(string $price): void
    {
        $this->seedDraft();
        $this->database->insert('bib_entitlements', ['id' => '00000000-0000-0000-0000-000000000030', 'race_id' => '00000000-0000-0000-0000-000000000005', 'bib_number' => '00042', 'source_kind' => 'document', 'current_owner_account_id' => self::SELLER]);
        $client = $this->sellerClient();
        $crawler = $client->request('GET', '/compte/ventes');
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['price' => $price]));
        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('[role="alert"]');
        self::assertSame($price, $client->getCrawler()->filter('input[name="price"]')->attr('value'));
        self::assertSame($form->getPhpValues()['listing_id'], $client->getCrawler()->filter('input[name="listing_id"]')->attr('value'));
        self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM resale_listings WHERE entitlement_id='00000000-0000-0000-0000-000000000030'"));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPrices(): iterable
    {
        yield 'too many decimals' => ['100,001'];
        yield 'scientific notation' => ['1e2'];
        yield 'zero' => ['0'];
        yield 'zero seller net' => ['5,00'];
        yield 'negative' => ['-100'];
        yield 'fee overflow' => ['92233720368547758,07'];
        yield 'cent overflow' => ['92233720368547758,08'];
    }

    public function testSellerUsesApprovedProofAndVersionedFormsToPublishRepriceAndWithdraw(): void
    {
        $this->seedDraft();
        $this->database->executeStatement("INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,entitlement_id,source_kind,private_object_id,submitted_at,decision,reviewed_version,reviewer_id,reviewed_at) SELECT '00000000-0000-0000-0000-000000000031',?,race_id,proposed_bib_number,entitlement_id,source_kind,private_object_id,submitted_at,decision,reviewed_version,reviewer_id,reviewed_at FROM ownership_proofs WHERE id=?", [self::MEMBER, self::PROOF]);
        $previousScope = $_SERVER['STRIPE_PROVIDER_SCOPE'] ?? null;
        $_SERVER['STRIPE_PROVIDER_SCOPE'] = $_ENV['STRIPE_PROVIDER_SCOPE'] = 'controlled:test';
        try {
            $this->database->insert('seller_accounts', ['id' => '00000000-0000-0000-0000-000000000021', 'seller_id' => self::SELLER, 'provider' => 'stripe', 'provider_scope' => 'controlled:test', 'connected_account_ref' => 'acct_controlled', 'readiness_state' => 'ready', 'last_reconciled_at' => '2026-10-13Z']);
            $client = self::createClient();
            $client->disableReboot();
            self::getContainer()->set(\App\Application\Finance\SellerProvider::class, new ControlledSellerProvider());
            $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('seller@example.test'));
            $crawler = $client->request('GET', '/compte/ventes');
            self::assertSelectorTextContains('[data-seller-margin]', '7,50 €');
            self::assertSelectorNotExists('option[value="00000000-0000-0000-0000-000000000031"]');
            self::assertSelectorTextNotContains('main', 'opaque-proof');
            $proofForm = $crawler->selectButton('Joindre le justificatif')->form(['proof_id' => self::PROOF]);
            $client->submit($proofForm);
            self::assertResponseRedirects('/compte/ventes', 303);
            self::assertSame(['proof_id' => self::PROOF, 'approved_proof_version' => 1, 'version' => 2], $this->database->fetchAssociative('SELECT proof_id::text,approved_proof_version,version FROM resale_listings WHERE id=?', [self::DRAFT]));
            $client->submit($proofForm);
            self::assertResponseStatusCodeSame(409);
            self::assertSelectorExists('[role="alert"]');
            $crawler = $client->request('GET', '/compte/ventes');
            $client->submit($crawler->selectButton('Publier l’annonce')->form());
            self::assertResponseRedirects('/compte/ventes', 303);
            self::assertSame(['state' => 'published', 'version' => 3], $this->database->fetchAssociative('SELECT state,version FROM resale_listings WHERE id=?', [self::DRAFT]));
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
            $crawler = $client->followRedirect();
            $client->submit($crawler->selectButton('Enregistrer le nouveau prix')->form(['price' => '200,00']));
            self::assertResponseRedirects('/compte/ventes', 303);
            self::assertSame(['price_cents' => 20000, 'offer_version' => 2, 'version' => 4], $this->database->fetchAssociative('SELECT price_cents,offer_version,version FROM resale_listings WHERE id=?', [self::DRAFT]));
            self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
            self::assertSame('repriced', $this->database->fetchOne('SELECT invalidation_reason FROM availability_events WHERE offer_version=1'));
            $crawler = $client->followRedirect();
            self::assertSelectorTextContains('[data-seller-margin]', '15,00 €');
            self::assertSelectorTextContains('[data-seller-net]', '185,00 €');
            self::assertSelectorTextContains('main', '72 heures');
            $withdrawForm = $crawler->selectButton('Retirer l’annonce')->form();
            $client->submit($withdrawForm);
            self::assertResponseRedirects('/compte/ventes', 303);
            self::assertSame(['state' => 'withdrawn', 'version' => 5], $this->database->fetchAssociative('SELECT state,version FROM resale_listings WHERE id=?', [self::DRAFT]));
            self::assertSame('withdrawn', $this->database->fetchOne('SELECT invalidation_reason FROM availability_events WHERE offer_version=2'));
            $client->followRedirect();
            self::assertSelectorNotExists('input[name="action"][value="publier"]');
            self::assertSelectorNotExists('input[name="action"][value="prix"]');
            self::assertSelectorCount(1, 'form[action$="/brouillon"]');
            $client->submit($withdrawForm);
            self::assertResponseStatusCodeSame(409);
            self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='listing.withdrawn'"));
        } finally {
            if (null === $previousScope) {
                unset($_SERVER['STRIPE_PROVIDER_SCOPE'], $_ENV['STRIPE_PROVIDER_SCOPE']);
            } else {
                $_SERVER['STRIPE_PROVIDER_SCOPE'] = $_ENV['STRIPE_PROVIDER_SCOPE'] = $previousScope;
            }
        }
    }

    public function testOwnerCreatesDraftWithExactFrenchDecimalPriceBeforeOnboarding(): void
    {
        $this->seed();
        $seller = '00000000-0000-0000-0000-000000000001';
        $entitlement = '00000000-0000-0000-0000-000000000030';
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'seller@example.test', 'email_normalized' => 'seller@example.test']);
        $this->database->insert('bib_entitlements', ['id' => $entitlement, 'race_id' => '00000000-0000-0000-0000-000000000005', 'bib_number' => '00042', 'source_kind' => 'document', 'current_owner_account_id' => $seller]);
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('seller@example.test'));
        $crawler = $client->request('GET', '/compte/ventes');
        $form = $crawler->selectButton('Enregistrer le brouillon')->form(['price' => '100,10']);
        $client->submit($form);
        self::assertResponseRedirects('/compte/ventes', 303);
        self::assertSame(['seller_id' => $seller, 'entitlement_id' => $entitlement, 'price_cents' => 10010, 'state' => 'draft'], $this->database->fetchAssociative('SELECT seller_id::text,entitlement_id::text,price_cents,state FROM resale_listings WHERE entitlement_id=?', [$entitlement]));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM seller_accounts'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM availability_events'));
        $client->followRedirect();
        self::assertSelectorTextContains('main', '00042');
        self::assertSelectorTextContains('main', '105,11 €');
        self::assertSelectorTextContains('main', '92,59 €');
        $client->submit($form);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM resale_listings WHERE entitlement_id=?', [$entitlement]));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='listing.drafted'"));
    }

    public function testSellerSeesOnlyTheirBibsWithExactFeesAndTextualIdentity(): void
    {
        $this->seed();
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('account_private_profiles', ['account_id' => $seller, 'email' => 'seller@example.test', 'email_normalized' => 'seller@example.test']);
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer-private@example.test', 'email_normalized' => 'buyer-private@example.test']);
        $this->database->insert('bib_entitlements', ['id' => '00000000-0000-0000-0000-000000000030', 'race_id' => '00000000-0000-0000-0000-000000000005', 'bib_number' => '00999', 'source_kind' => 'document', 'current_owner_account_id' => self::MEMBER]);
        $client = self::createClient();
        $client->request('GET', '/compte/ventes');
        self::assertResponseRedirects('/connexion');
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('seller@example.test'));
        $client->request('GET', '/compte/ventes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mes dossards et annonces');
        self::assertSelectorTextContains('main', '00123');
        self::assertSelectorTextContains('main', 'Paris Marathon');
        self::assertSelectorTextContains('[data-buyer-total]', '105,00 €');
        self::assertSelectorTextContains('[data-seller-net]', '92,50 €');
        self::assertSelectorTextContains('main', 'En vente');
        self::assertSelectorTextNotContains('main', '00999');
        self::assertSelectorTextNotContains('main', 'buyer-private@example.test');
        self::assertSelectorExists('a[href="/compte/vendeur"]');
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('buyer-private@example.test'));
        $client->request('GET', '/compte/ventes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '00999');
        self::assertSelectorTextNotContains('main', '00123');
    }

    private function seedDraft(): void
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::SELLER, 'email' => 'seller@example.test', 'email_normalized' => 'seller@example.test']);
        $this->database->executeStatement("UPDATE resale_listings SET state='withdrawn',withdrawn_at='2026-10-13Z'");
        $this->database->insert('resale_listings', ['id' => self::DRAFT, 'race_id' => self::RACE, 'entitlement_id' => '00000000-0000-0000-0000-000000000006', 'seller_id' => self::SELLER, 'price_cents' => 10000]);
    }

    private function sellerClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('seller@example.test'));

        return $client;
    }
}
