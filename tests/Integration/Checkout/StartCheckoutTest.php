<?php

declare(strict_types=1);

namespace App\Tests\Integration\Checkout;

use App\Application\Checkout\AuthorizeCheckout;
use App\Application\Checkout\StartCheckout;
use App\Application\Finance\SubmitHostedCheckout;
use App\Infrastructure\Checkout\DbalCheckoutAuthorizationStore;
use App\Tests\Integration\Availability\AvailabilityTestCase;
use App\Tests\Integration\Finance\ControlledHostedCheckoutProvider;

final class StartCheckoutTest extends AvailabilityTestCase
{
    public function testAcknowledgedCartStartsOneProtectedHostedPayment(): void
    {
        $provider = new ControlledHostedCheckoutProvider();
        $start = new StartCheckout($this->authorization(), new SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider));
        $result = $start->start(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertSame('https://checkout.stripe.test/session', $result->paymentUrl);
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
        self::assertSame('pending', $this->database->fetchOne('SELECT state FROM checkouts'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame('transfer-v1', $this->database->fetchOne('SELECT acknowledgment_text_version FROM checkout_items'));
        self::assertSame($result->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
        self::assertSame(10500, $this->database->fetchOne('SELECT expected_total_cents FROM checkouts'));
        self::assertCount(1, $provider->keys);
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM outbox_messages WHERE event_type='checkout.payment_prepared'"));
    }

    public function testMissingAcknowledgmentNeverStartsPayment(): void
    {
        $provider = new ControlledHostedCheckoutProvider();
        $start = new StartCheckout($this->authorization(), new SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider));
        try {
            $start->start(self::MEMBER, [self::LISTING => new \stdClass()], [], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('Checkout started without the transfer acknowledgment.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkout_items'));
            self::assertSame([], $provider->keys);
        }
    }

    public function testUnavailableProviderKeepsTheProtectedCheckoutForReconciliation(): void
    {
        $provider = new ControlledHostedCheckoutProvider();
        $provider->failNext = true;
        $start = new StartCheckout($this->authorization(), new SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider));
        $result = $start->start(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertNull($result->paymentUrl);
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame('unknown', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
    }

    public function testRaceRequiredRegistrationFieldsAreExposedValidatedAndCommitted(): void
    {
        $authorization = $this->authorization();
        $this->database->update('races', ['registration_schema' => '{"type":"object","properties":{"nom":{"type":"string"},"taille":{"type":"integer"},"club":{"type":"boolean"},"categorie":{"type":"string","enum":["A","B"]}},"required":["nom","taille"],"additionalProperties":false}', 'registration_schema_version' => 1], ['id' => self::RACE]);
        $view = (new \App\Application\Checkout\ManageCart(new \App\Infrastructure\Checkout\DbalCartStore($this->database), new \App\Application\Availability\FindOfferAccess(new \App\Infrastructure\Availability\DbalAvailabilityAccessStore($this->database)), new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Domain\Finance\FeePolicy()))->view(self::MEMBER, new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertSame([
            ['name' => 'nom', 'type' => 'string', 'required' => true, 'options' => null],
            ['name' => 'club', 'type' => 'boolean', 'required' => false, 'options' => null],
            ['name' => 'taille', 'type' => 'integer', 'required' => true, 'options' => null],
            ['name' => 'categorie', 'type' => 'string', 'required' => false, 'options' => ['A', 'B']],
        ], $view->items[0]->registrationFields);
        $answers = (object) ['nom' => 'Ada', 'taille' => 38, 'club' => true, 'categorie' => 'A'];
        try {
            $authorization->authorize(self::MEMBER, [self::LISTING => (object) ['nom' => 'Ada', 'taille' => 'grand']], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('Invalid registration answer was accepted.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
        }
        $authorization->authorize(self::MEMBER, [self::LISTING => $answers], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $raw = $this->database->fetchOne('SELECT registration_fields::text FROM checkout_items');
        self::assertIsString($raw);
        $stored = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        self::assertSame('Ada', $stored['nom']);
        self::assertSame(38, $stored['taille']);
        self::assertTrue($stored['club']);
        self::assertSame('A', $stored['categorie']);
    }

    public function testRefusedSubmissionKeepsTheProtectedCheckoutForReconciliation(): void
    {
        $store = new class implements \App\Application\Finance\PaymentSubmissionStore {
            public function prepare(string $operationId, string $checkoutId, \DateTimeImmutable $now, string $worker): \App\Application\Finance\HostedCheckoutIntent
            {
                throw new \DomainException('Checkout submission is unavailable.');
            }

            public function unknown(\App\Application\Finance\HostedCheckoutIntent $intent): void
            {
            }

            public function recordSession(\App\Application\Finance\HostedCheckoutIntent $intent, \App\Application\Finance\HostedCheckoutSession $session, \DateTimeImmutable $now): void
            {
            }
        };
        $start = new StartCheckout($this->authorization(), new SubmitHostedCheckout($store, new ControlledHostedCheckoutProvider()));
        $result = $start->start(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertNull($result->paymentUrl);
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
    }

    private function authorization(): AuthorizeCheckout
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test', 'display_name' => 'Buyer']);
        $this->database->insert('seller_accounts', ['id' => '00000000-0000-0000-0000-000000000050', 'seller_id' => '00000000-0000-0000-0000-000000000001', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'connected_account_ref' => 'acct_recipient', 'readiness_state' => 'ready']);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $this->database->insert('cart_items', ['buyer_id' => self::MEMBER, 'listing_id' => self::LISTING, 'added_at' => '2026-10-10T11:00:00Z']);

        $sellerProvider = new \App\Tests\Integration\Finance\ControlledSellerProvider();
        $sellerProvider->status = \App\Domain\Finance\SellerReadiness::Ready;

        return new AuthorizeCheckout(new DbalCheckoutAuthorizationStore($this->database), new \App\Application\Availability\FindOfferAccess(new \App\Infrastructure\Availability\DbalAvailabilityAccessStore($this->database)), new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database), new \App\Domain\Finance\FeePolicy(), 'test:platform', 'fees-v1', ['success_url' => 'https://runnerbib.test/achat/retour?session={CHECKOUT_SESSION_ID}', 'cancel_url' => 'https://runnerbib.test/achat/retour?session={CHECKOUT_SESSION_ID}', 'api_version' => '2024-06-20', 'automatic_tax' => false], 'transfer-v1');
    }
}
