<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Infrastructure\Identity\IdentityUserProvider;
use App\Tests\Integration\Availability\AvailabilityTestCase;

final class CheckoutStartPagesTest extends AvailabilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['STRIPE_PROVIDER_SCOPE' => 'test:platform', 'APP_BASE_URL' => 'https://localhost:8082'] as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_ENV['STRIPE_PROVIDER_SCOPE'], $_SERVER['STRIPE_PROVIDER_SCOPE'], $_ENV['APP_BASE_URL'], $_SERVER['APP_BASE_URL']);
    }

    public function testAnonymousBuyerCannotStartOrInspectAPayment(): void
    {
        $client = self::createClient();
        $client->request('POST', '/panier/payer');
        self::assertResponseRedirects('/connexion');
        $client->request('GET', '/achat/retour?session=cs_hosted');
        self::assertResponseRedirects('/connexion');
    }

    public function testPaymentFormShowsTheTransferAcknowledgmentAndRefusesAnUnpayableCart(): void
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test', 'display_name' => 'Buyer']);
        $this->database->insert('seller_accounts', ['id' => '00000000-0000-0000-0000-000000000050', 'seller_id' => '00000000-0000-0000-0000-000000000001', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'connected_account_ref' => 'acct_recipient', 'readiness_state' => 'ready']);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $this->database->insert('cart_items', ['buyer_id' => self::MEMBER, 'listing_id' => self::LISTING, 'added_at' => '2026-10-10T11:00:00Z']);
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('buyer@example.test'));
        $crawler = $client->request('GET', '/panier');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="acknowledgment['.self::RACE.']"]');
        self::assertSelectorNotExists('input[name="acknowledgment['.self::RACE.']"][checked]');
        self::assertSelectorTextContains('main', 'Je comprends et je suivrai la procédure de transfert');
        $client->submit($crawler->selectButton('Payer')->form(), ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
        $this->database->delete('cart_items', ['buyer_id' => self::MEMBER]);
        $client->submit($crawler->selectButton('Payer')->form());
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
        $this->database->insert('cart_items', ['buyer_id' => self::MEMBER, 'listing_id' => self::LISTING, 'added_at' => '2026-10-10T11:00:00Z']);
        $client->submit($crawler->selectButton('Payer')->form());
        self::assertResponseStatusCodeSame(503);
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkout_items'));
        self::assertFalse($this->database->fetchOne('SELECT reserved_checkout_id FROM buyer_race_guards'));
    }

    public function testRaceRequiredRegistrationFieldsAreOfferedInThePaymentForm(): void
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test', 'display_name' => 'Buyer']);
        $this->database->insert('seller_accounts', ['id' => '00000000-0000-0000-0000-000000000050', 'seller_id' => '00000000-0000-0000-0000-000000000001', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'connected_account_ref' => 'acct_recipient', 'readiness_state' => 'ready']);
        $this->database->update('races', ['registration_schema' => '{"type":"object","properties":{"nom":{"type":"string"},"taille":{"type":"integer"},"club":{"type":"boolean"},"categorie":{"type":"string","enum":["A","B"]}},"required":["nom","taille"],"additionalProperties":false}', 'registration_schema_version' => 1], ['id' => self::RACE]);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $this->database->insert('cart_items', ['buyer_id' => self::MEMBER, 'listing_id' => self::LISTING, 'added_at' => '2026-10-10T11:00:00Z']);
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('buyer@example.test'));
        $crawler = $client->request('GET', '/panier');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="registration['.self::LISTING.'][nom]"]');
        self::assertSelectorExists('input[name="registration['.self::LISTING.'][taille]"]');
        self::assertSelectorExists('input[name="registration['.self::LISTING.'][club]"][type="checkbox"]');
        self::assertSelectorExists('select[name="registration['.self::LISTING.'][categorie]"]');
        $client->submit($crawler->selectButton('Payer')->form(), [
            'registration['.self::LISTING.'][nom]' => 'Ada',
            'registration['.self::LISTING.'][taille]' => '38',
            'registration['.self::LISTING.'][club]' => '1',
            'registration['.self::LISTING.'][categorie]' => 'A',
            'acknowledgment['.self::RACE.']' => '1',
        ]);
        self::assertResponseStatusCodeSame(503);
        $client->request('POST', '/panier/payer', [
            '_token' => $crawler->filter('form[action="/panier/payer"] input[name="_token"]')->attr('value'),
            'registration' => [self::LISTING => ['nom' => ['nested'], 'taille' => '38']],
            'acknowledgment' => [self::RACE => '1'],
        ]);
        self::assertResponseStatusCodeSame(503);
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
    }

    /** @param string|list<string> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRegistrationAnswers')]
    public function testInvalidRegistrationAnswersNeverCreateAHostedPayment(string $field, string|array $answer, string $acknowledgment = '1'): void
    {
        [$client, $crawler, $provider] = $this->registrationPaymentForm();
        $answers = ['taille' => '38', 'club' => '1'];
        $answers[$field] = $answer;
        $client->request('POST', '/panier/payer', [
            '_token' => $crawler->filter('form[action="/panier/payer"] input[name="_token"]')->attr('value'),
            'registration' => [self::LISTING => $answers],
            'acknowledgment' => [self::RACE => $acknowledgment],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
        self::assertSame([], $provider->keys);
    }

    /** @return iterable<array{0:string,1:string|list<string>,2?:string}> */
    public static function invalidRegistrationAnswers(): iterable
    {
        yield 'fractional integer' => ['taille', '38.5'];
        yield 'scientific notation' => ['taille', '3.8e1'];
        yield 'integer overflow' => ['taille', '999999999999999999999999999999999999'];
        yield 'unknown boolean' => ['club', 'perhaps'];
        yield 'nested optional answer' => ['club', ['nested']];
        yield 'declined acknowledgment' => ['taille', '38', '0'];
    }

    public function testValidZeroAndFalseRegistrationAnswersAreCommittedWithoutLosingTheirTypes(): void
    {
        [$client, $crawler, $provider] = $this->registrationPaymentForm();
        $client->request('POST', '/panier/payer', [
            '_token' => $crawler->filter('form[action="/panier/payer"] input[name="_token"]')->attr('value'),
            'registration' => [self::LISTING => ['taille' => '0', 'club' => '0']],
            'acknowledgment' => [self::RACE => '1'],
        ]);
        self::assertResponseRedirects('https://checkout.stripe.test/session');
        $raw = $this->database->fetchOne('SELECT registration_fields::text FROM checkout_items');
        self::assertIsString($raw);
        $answers = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($answers);
        self::assertSame(0, $answers['taille']);
        self::assertFalse($answers['club']);
        self::assertCount(1, $provider->keys);
    }

    public function testProviderReturnPageShowsTheAuthoritativeStateOfTheBuyerCheckoutOnly(): void
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test']);
        $this->database->insert('checkouts', ['id' => '00000000-0000-0000-0000-000000000090', 'buyer_id' => self::MEMBER, 'state' => 'processing', 'authorized_at' => '2026-10-10T11:00:00Z', 'initial_deadline' => '2026-10-10T11:15:00Z', 'expected_total_cents' => 10500, 'currency' => 'EUR']);
        $this->database->insert('financial_operations', ['id' => '00000000-0000-0000-0000-000000000092', 'semantic_key' => 'checkout:90', 'kind' => 'checkout_payment', 'checkout_id' => '00000000-0000-0000-0000-000000000090', 'expected_amount_cents' => 10500, 'currency' => 'EUR', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'parameters' => '{}', 'parameters_hash' => 'hash', 'created_at' => '2026-10-10T11:00:00Z']);
        $this->database->insert('payment_attempts', ['id' => '00000000-0000-0000-0000-000000000091', 'checkout_id' => '00000000-0000-0000-0000-000000000090', 'attempt_no' => 1, 'expected_amount_cents' => 10500, 'currency' => 'EUR', 'state' => 'submitted', 'session_ref' => 'cs_returned', 'operation_id' => '00000000-0000-0000-0000-000000000092', 'provider' => 'stripe', 'provider_scope' => 'test:platform']);
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('buyer@example.test'));
        $client->request('GET', '/achat/retour?session=cs_returned');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'traitement');
        self::assertSelectorTextContains('main', '105,00');
        self::assertSelectorExists('a[href="/achat/00000000-0000-0000-0000-000000000090/retour"]');
        $client->request('GET', '/achat/retour?session=cs_unknown');
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{\Symfony\Bundle\FrameworkBundle\KernelBrowser,\Symfony\Component\DomCrawler\Crawler,\App\Tests\Integration\Finance\ControlledHostedCheckoutProvider} */
    private function registrationPaymentForm(): array
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test', 'display_name' => 'Buyer']);
        $this->database->insert('seller_accounts', ['id' => '00000000-0000-0000-0000-000000000050', 'seller_id' => '00000000-0000-0000-0000-000000000001', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'connected_account_ref' => 'acct_recipient', 'readiness_state' => 'ready']);
        $this->database->update('races', ['registration_schema' => '{"type":"object","properties":{"taille":{"type":"integer"},"club":{"type":"boolean"}},"required":["taille"],"additionalProperties":false}', 'registration_schema_version' => 1], ['id' => self::RACE]);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $this->database->insert('cart_items', ['buyer_id' => self::MEMBER, 'listing_id' => self::LISTING, 'added_at' => '2026-10-10T11:00:00Z']);
        $client = self::createClient();
        $client->disableReboot();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('buyer@example.test'));
        $crawler = $client->request('GET', '/panier');
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        self::getContainer()->set(\App\Application\Finance\HostedCheckoutProvider::class, $provider);

        return [$client, $crawler, $provider];
    }
}
