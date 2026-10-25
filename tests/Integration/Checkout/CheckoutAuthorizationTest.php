<?php

declare(strict_types=1);

namespace App\Tests\Integration\Checkout;

use App\Application\Checkout\AuthorizeCheckout;
use App\Infrastructure\Checkout\DbalCheckoutAuthorizationStore;
use App\Tests\Integration\Availability\AvailabilityTestCase;

final class CheckoutAuthorizationTest extends AvailabilityTestCase
{
    public function testAuthorizationCommitsWholeProtectionAndMoneyOnlyPaymentIntent(): void
    {
        $checkout = $this->checkout();
        $view = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertSame(10500, $view->totalCents);
        self::assertSame('2026-10-10T11:15:00+00:00', $view->initialDeadline->format(DATE_ATOM));
        self::assertSame($view->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
        self::assertSame(9250, $this->database->fetchOne('SELECT seller_net_cents FROM checkout_items'));
        $parameters = $this->database->fetchOne('SELECT parameters::text FROM financial_operations');
        self::assertIsString($parameters);
        self::assertStringNotContainsString('buyer@example.test', $parameters);
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM outbox_messages WHERE event_type='checkout.payment_prepared'"));
    }

    public function testSecondCheckoutCannotReserveTheSameRaceForOneBuyer(): void
    {
        $checkout = $this->checkout();
        $first = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertSame($first->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
        $this->database->insert('accounts', ['id' => '00000000-0000-0000-0000-0000000000c1', 'roles' => '["runner"]', 'verified_at' => '2026-10-10Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-10Z']);
        $this->database->insert('account_private_profiles', ['account_id' => '00000000-0000-0000-0000-0000000000c1', 'email' => 'other-seller@example.test', 'email_normalized' => 'other-seller@example.test']);
        $this->database->insert('bib_entitlements', ['id' => '00000000-0000-0000-0000-0000000000b1', 'race_id' => self::RACE, 'bib_number' => '00124', 'source_kind' => 'document', 'current_owner_account_id' => '00000000-0000-0000-0000-0000000000c1']);
        $this->database->insert('private_objects', ['id' => '00000000-0000-0000-0000-0000000000c4', 'object_key' => 'opaque-proof-b', 'category' => 'ownership_proof', 'mime_type' => 'application/pdf', 'size_bytes' => 100, 'checksum' => 'checksum-b', 'created_at' => '2026-10-10Z']);
        $this->database->insert('ownership_proofs', ['id' => '00000000-0000-0000-0000-0000000000c5', 'submitted_by' => '00000000-0000-0000-0000-0000000000c1', 'race_id' => self::RACE, 'proposed_bib_number' => '00124', 'entitlement_id' => '00000000-0000-0000-0000-0000000000b1', 'source_kind' => 'document', 'private_object_id' => '00000000-0000-0000-0000-0000000000c4', 'submitted_at' => '2026-10-10T10:00:00Z', 'decision' => 'approved', 'reviewed_version' => 1, 'reviewer_id' => self::MEMBER, 'reviewed_at' => '2026-10-10T10:00:00Z']);
        $this->database->insert('resale_listings', ['id' => '00000000-0000-0000-0000-0000000000b2', 'race_id' => self::RACE, 'entitlement_id' => '00000000-0000-0000-0000-0000000000b1', 'seller_id' => '00000000-0000-0000-0000-0000000000c1', 'proof_id' => '00000000-0000-0000-0000-0000000000c5', 'approved_proof_version' => 1, 'price_cents' => 11000, 'state' => 'published', 'published_at' => '2026-10-10T10:00:00Z']);
        $this->database->insert('availability_events', ['id' => '00000000-0000-0000-0000-0000000000b3', 'listing_id' => '00000000-0000-0000-0000-0000000000b2', 'race_id' => self::RACE, 'offer_version' => 1, 'price_cents' => 11000, 'currency' => 'EUR', 'available_at' => '2026-10-10T10:00:00Z', 'priority_ends_at' => '2026-10-10T11:00:00Z', 'recipient_count' => 0]);
        $checkoutId = '00000000-0000-0000-0000-0000000000b4';
        $item = ['id' => '00000000-0000-0000-0000-0000000000b5', 'checkout_id' => $checkoutId, 'race_id' => self::RACE, 'entitlement_id' => '00000000-0000-0000-0000-0000000000b1', 'listing_id' => '00000000-0000-0000-0000-0000000000b2', 'seller_id' => '00000000-0000-0000-0000-0000000000c1', 'availability_event_id' => '00000000-0000-0000-0000-0000000000b3', 'offer_version' => 1, 'price_cents' => 11000, 'buyer_fee_cents' => 550, 'seller_margin_cents' => 825, 'buyer_total_cents' => 11550, 'seller_net_cents' => 10175, 'currency' => 'EUR', 'fee_policy_version' => 'fees-v1', 'registration_schema_version' => null, 'registration_schema' => null, 'buyer_name' => 'Buyer', 'buyer_email' => 'buyer@example.test', 'registration_fields' => '{}', 'participation_snapshot' => 'participating', 'acknowledgment_text_version' => null, 'acknowledgment_accepted_at' => null, 'initial_expires_at' => '2026-10-10T11:15:00Z'];
        try {
            $this->database->transactional(fn () => (new DbalCheckoutAuthorizationStore($this->database))->commit(new \App\Application\Checkout\CheckoutAuthorizationView($checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 11550, 'EUR', '00000000-0000-0000-0000-0000000000b6'), self::MEMBER, new \DateTimeImmutable('2026-10-10T11:00:00Z'), [$item], '{}', 'test:platform'));
            self::fail('A second checkout reserved the same race for one buyer.');
        } catch (\DomainException $error) {
            self::assertSame('Race is already reserved by another checkout.', $error->getMessage());
            self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
            self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM checkout_items'));
        }
    }

    public function testAuthorizationUsesConfirmedReadinessWithoutRefreshingTheProvider(): void
    {
        $checkout = $this->checkout();
        $view = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertSame($view->checkoutId, $this->database->fetchOne('SELECT id::text FROM checkouts'));
        self::assertSame('ready', $this->database->fetchOne('SELECT readiness_state FROM seller_accounts'));
        self::assertTrue((bool) $this->database->fetchOne("SELECT last_reconciled_at='2026-10-10T10:00:00Z' FROM seller_accounts"));
    }

    public function testUnapprovedAcknowledgmentCannotCommitCheckout(): void
    {
        $checkout = $this->checkout();
        try {
            $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'invented-client-version'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('Unapproved acknowledgment accepted.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkout_items'));
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
        }
    }

    public function testCommittedSnapshotsRejectPriceAndIdentityEdits(): void
    {
        $checkout = $this->checkout();
        $view = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $this->database->update('account_private_profiles', ['display_name' => 'Changed', 'email' => 'changed@example.test'], ['account_id' => self::MEMBER]);
        self::assertSame('Buyer', $this->database->fetchOne('SELECT buyer_name FROM checkout_items'));
        self::assertSame('fees-v1', $this->database->fetchOne('SELECT fee_policy_version FROM checkout_items'));
        self::assertSame('transfer-v1', $this->database->fetchOne('SELECT acknowledgment_text_version FROM checkout_items'));
        try {
            $this->database->update('checkout_items', ['price_cents' => 20000], ['checkout_id' => $view->checkoutId]);
            self::fail('Committed price changed.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertSame(10000, $this->database->fetchOne('SELECT price_cents FROM checkout_items'));
        }
    }

    public function testMissingAcknowledgmentAndRegistrationNeverLeavePartialIntent(): void
    {
        $checkout = $this->checkout();
        foreach ([[self::LISTING => new \stdClass()], []] as $registration) {
            try {
                $checkout->authorize(self::MEMBER, $registration, [], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
                self::fail('Incomplete terms accepted.');
            } catch (\DomainException) {
                self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkout_items'));
                self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM buyer_race_guards'));
                self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
            }
        }
    }

    public function testASecondBuyerCannotAuthorizeAnAlreadyProtectedBib(): void
    {
        $checkout = $this->checkout();
        $first = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $this->database->insert('account_private_profiles', ['account_id' => self::LATE, 'email' => 'other@example.test', 'email_normalized' => 'other@example.test', 'display_name' => 'Other']);
        $this->database->insert('cart_items', ['buyer_id' => self::LATE, 'listing_id' => self::LISTING, 'added_at' => '2026-10-10T11:00:00Z']);
        try {
            $checkout->authorize(self::LATE, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:01Z'));
            self::fail('Protected bib authorized twice.');
        } catch (\DomainException) {
            self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
            self::assertSame($first->checkoutId, $this->database->fetchOne('SELECT checkout_id::text FROM checkout_items'));
        }
    }

    public function testRequiredRegistrationSchemaRejectsIncompleteAnswers(): void
    {
        $checkout = $this->checkout();
        $schema = '{"type":"object","properties":{"license":{"type":"string","minLength":1}},"required":["license"],"additionalProperties":false}';
        $this->database->update('races', ['registration_schema' => $schema, 'registration_schema_version' => 2], ['id' => self::RACE]);
        try {
            $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('Incomplete registration accepted.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
        }
        $checkout->authorize(self::MEMBER, [self::LISTING => (object) ['license' => 'ABC001']], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertSame(2, $this->database->fetchOne('SELECT registration_schema_version FROM checkout_items'));
        self::assertSame('ABC001', $this->database->fetchOne("SELECT registration_fields->>'license' FROM checkout_items"));
    }

    public function testMissingFeePolicyConfigurationFailsClosedBeforeAuthorization(): void
    {
        $this->expectException(\DomainException::class);
        $this->checkout('');
    }

    public function testConcurrentBuyersReachSharedRaceLockAndOnlyOneAuthorizes(): void
    {
        $this->checkout();
        $this->database->insert('account_private_profiles', ['account_id' => self::LATE, 'email' => 'other@example.test', 'email_normalized' => 'other@example.test', 'display_name' => 'Other']);
        $this->database->insert('cart_items', ['buyer_id' => self::LATE, 'listing_id' => self::LISTING, 'added_at' => '2026-10-10T11:00:00Z']);
        $code = <<<'CODE'
require 'vendor/autoload.php';
$database=\Doctrine\DBAL\DriverManager::getConnection(json_decode(getenv('CHECKOUT_TEST_PARAMS'),true,512,JSON_THROW_ON_ERROR));
$database->executeQuery("SELECT set_config('application_name',?,false)",[getenv('CHECKOUT_TEST_NAME')]);
$provider=new \App\Tests\Integration\Finance\ControlledSellerProvider();
$provider->status=\App\Domain\Finance\SellerReadiness::Ready;
$checkout=new \App\Application\Checkout\AuthorizeCheckout(new \App\Infrastructure\Checkout\DbalCheckoutAuthorizationStore($database),new \App\Application\Availability\FindOfferAccess(new \App\Infrastructure\Availability\DbalAvailabilityAccessStore($database)),new \App\Infrastructure\Operations\DbalTransactionManager($database),new \App\Infrastructure\Operations\DbalOutboxWriter($database),new \App\Domain\Finance\FeePolicy(),'test:platform','fees-v1',['success_url'=>'https://runnerbib.test/success','cancel_url'=>'https://runnerbib.test/cancel','api_version'=>'2026-09-30.endive','automatic_tax'=>false],'transfer-v1');
try {
    $checkout->authorize($argv[1],['00000000-0000-0000-0000-000000000009'=>new \stdClass()],['00000000-0000-0000-0000-000000000004'=>'transfer-v1'],new \DateTimeImmutable('2026-10-10T11:00:00Z'));
    echo 'won';
} catch (\DomainException) { echo 'denied'; }
CODE;
        $name = 'checkout_'.bin2hex(random_bytes(6));
        $environment = ['CHECKOUT_TEST_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR), 'CHECKOUT_TEST_NAME' => $name];
        $first = new \Symfony\Component\Process\Process(['php', '-r', $code, self::MEMBER], dirname(__DIR__, 3), $environment);
        $second = new \Symfony\Component\Process\Process(['php', '-r', $code, self::LATE], dirname(__DIR__, 3), $environment);
        $this->database->beginTransaction();
        $this->database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [self::RACE]);
        try {
            $first->start();
            $second->start();
            $deadline = microtime(true) + 10;
            do {
                $this->database->executeQuery('SELECT pg_stat_clear_snapshot()');
                $blocked = $this->database->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$name]);
                if (2 === $blocked) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both authorizers must wait on the shared race row.');
            $this->database->commit();
            self::assertSame(0, $first->wait(), $first->getErrorOutput());
            self::assertSame(0, $second->wait(), $second->getErrorOutput());
            $results = [$first->getOutput(), $second->getOutput()];
            sort($results);
            self::assertSame(['denied', 'won'], $results);
            self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
            self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM checkout_items'));
            self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
        } finally {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
            $first->stop();
            $second->stop();
        }
    }

    public function testOneInvalidItemRollsBackTheWholeMultiRaceCheckout(): void
    {
        $checkout = $this->checkout();
        $this->addSecondRaceToCart();
        $answers = [self::LISTING => new \stdClass(), '00000000-0000-0000-0000-000000000063' => new \stdClass()];
        try {
            $checkout->authorize(self::MEMBER, $answers, [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('Second item acknowledgment omitted.');
        } catch (\DomainException) {
            foreach (['checkouts', 'checkout_items', 'buyer_race_guards', 'financial_operations'] as $table) {
                self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM '.$table));
            }
        }
        $view = $checkout->authorize(self::MEMBER, $answers, [self::RACE => 'transfer-v1', '00000000-0000-0000-0000-000000000005' => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertSame(31500, $view->totalCents);
        self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM checkout_items'));
        self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM buyer_race_guards'));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
    }

    /** @param array<string,mixed> $configuration */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeConfigurations')]
    public function testUnsafeProviderConfigurationCannotAuthorize(array $configuration): void
    {
        $this->expectException(\DomainException::class);
        $this->checkout('fees-v1', $configuration);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function unsafeConfigurations(): iterable
    {
        yield 'unexpected private data' => [['automatic_tax' => false, 'api_version' => '2026-09-30.endive', 'buyer_email' => 'private@example.test']];
        yield 'automatic tax' => [['automatic_tax' => true]];
    }

    public function testInsecureCallbackCannotAuthorize(): void
    {
        $checkout = $this->checkout('fees-v1', ['automatic_tax' => false, 'api_version' => '2026-09-30.endive', 'success_url' => 'http://insecure.test', 'cancel_url' => 'https://runnerbib.test/cancel']);
        $this->expectException(\DomainException::class);
        $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
    }

    public function testPendingSellerCannotAuthorizeOrCreateLocks(): void
    {
        $checkout = $this->checkout('fees-v1', null, \App\Domain\Finance\SellerReadiness::Pending);
        try {
            $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('Pending seller authorized.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkout_items'));
        }
    }

    public function testEmptyCartAndMissingBuyerIdentityCannotCommit(): void
    {
        $checkout = $this->checkout();
        $this->database->delete('cart_items', ['buyer_id' => self::MEMBER]);
        try {
            $checkout->authorize(self::MEMBER, [], [], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('Empty cart accepted.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkouts'));
        }
        $this->database->insert('cart_items', ['buyer_id' => self::MEMBER, 'listing_id' => self::LISTING, 'added_at' => '2026-10-10T11:00:00Z']);
        $this->database->delete('account_private_profiles', ['account_id' => self::MEMBER]);
        try {
            $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('Missing private buyer identity accepted.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
        }
    }

    public function testUnrequestedPrivateRegistrationFieldsAreRejected(): void
    {
        $checkout = $this->checkout();
        $this->expectException(\DomainException::class);
        $checkout->authorize(self::MEMBER, [self::LISTING => (object) ['medical_details' => 'not requested']], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
    }

    public function testHostedSubmissionRecordsOriginalIdentityAndDoesNotConfirmPurchase(): void
    {
        $checkout = $this->checkout();
        $authorization = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $submit = new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider());
        $session = $submit->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'worker-one');
        self::assertSame('cs_hosted', $session->sessionReference);
        self::assertSame('https://checkout.stripe.test/session', $session->url);
        self::assertSame('submitted', $this->database->fetchOne('SELECT state FROM payment_attempts'));
        self::assertSame('pending', $this->database->fetchOne('SELECT state FROM checkouts'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
        self::assertSame('checkout-payment:'.$authorization->operationId, $this->database->fetchOne('SELECT idempotency_key FROM financial_operation_attempts'));
    }

    public function testLostHostedResponseRetainsOneAttemptAndReusesOriginalIdempotencyKey(): void
    {
        $checkout = $this->checkout();
        $authorization = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $provider->failNext = true;
        $provider->onCreation = function (): void { self::assertFalse($this->database->isTransactionActive(), 'Provider must run after local intent commits.'); };
        $submit = new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider);
        try {
            $submit->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'worker-one');
            self::fail('Lost provider response was swallowed.');
        } catch (\RuntimeException) {
            self::assertSame('unknown', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
            self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        }
        $session = $submit->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:02Z'), 'worker-two');
        self::assertSame('cs_hosted', $session->sessionReference);
        self::assertSame(['checkout-payment:'.$authorization->operationId, 'checkout-payment:'.$authorization->operationId], $provider->keys);
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM payment_attempts'));
    }

    public function testRedeliveryRetrievesOriginalHostedSessionWithoutAnotherMutation(): void
    {
        $checkout = $this->checkout();
        $authorization = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $submit = new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider);
        $submit->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'worker-one');
        $session = $submit->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:02Z'), 'worker-two');
        self::assertSame('cs_hosted', $session->sessionReference);
        self::assertSame(['checkout-payment:'.$authorization->operationId], $provider->keys);
        self::assertSame(['cs_hosted'], $provider->retrieved);
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
    }

    public function testPaymentOutboxRouteValidatesCorrelationAndOnlySubmitsHostedSession(): void
    {
        $checkout = $this->checkout();
        $authorization = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $handler = new \App\Infrastructure\Finance\HostedCheckoutMessageHandler(new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider), new \Symfony\Component\Clock\MockClock('2026-10-10T11:00:01Z'));
        self::assertTrue($handler->supports('checkout.payment_prepared'));
        self::assertFalse($handler->supports('identity.credential_mail'));
        $message = new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000099', 'effect-one', 'checkout', $authorization->checkoutId, 1, 'checkout.payment_prepared', 1, json_encode(['operation_id' => $authorization->operationId], JSON_THROW_ON_ERROR), new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $handler($message);
        self::assertSame('cs_hosted', $this->database->fetchOne('SELECT session_ref FROM payment_attempts'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
        $handler($message);
        self::assertCount(1, $provider->keys);
        self::assertSame(['cs_hosted'], $provider->retrieved);
    }

    public function testUnknownPaymentPastInitialDeadlineKeepsProtectionWithoutBlindMutation(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $provider->failNext = true;
        $submit = new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider);
        try {
            $submit->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'worker-one');
        } catch (\RuntimeException) {
            self::assertSame('unknown', $this->database->fetchOne('SELECT state FROM payment_attempts'));
        }
        try {
            $submit->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'worker-two');
            self::fail('Unknown payment blindly replayed after initial deadline.');
        } catch (\DomainException) {
            self::assertCount(1, $provider->keys);
            self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
            self::assertSame('unknown', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
        }
    }

    public function testAnotherWorkerCannotMutateWhileOriginalProviderCallOwnsLease(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $other = \Doctrine\DBAL\DriverManager::getConnection($this->database->getParams());
        $second = new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($other, 'test:platform'), $provider);
        $provider->onCreation = function () use ($authorization, $second, $provider): void {
            try {
                $second->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:02Z'), 'worker-two');
                self::fail('Second worker took a live attempt.');
            } catch (\DomainException) {
                self::assertCount(1, $provider->keys);
            }
        };
        try {
            $first = new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider);
            $first->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'worker-one');
            self::assertSame('succeeded', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
            self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM payment_attempts'));
        } finally {
            $other->close();
        }
    }

    public function testMismatchedPaymentOutboxAggregateCannotSubmitAnotherCheckout(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $handler = new \App\Infrastructure\Finance\HostedCheckoutMessageHandler(new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider), new \Symfony\Component\Clock\MockClock('2026-10-10T11:00:01Z'));
        try {
            $handler(new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000098', 'effect-forged', 'checkout', '00000000-0000-0000-0000-000000000099', 1, 'checkout.payment_prepared', 1, json_encode(['operation_id' => $authorization->operationId], JSON_THROW_ON_ERROR), new \DateTimeImmutable('2026-10-10T11:00:00Z')));
            self::fail('Unrelated checkout aggregate accepted.');
        } catch (\DomainException) {
            self::assertSame([], $provider->keys);
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
        }
    }

    public function testMalformedOrUnsupportedPaymentMessagesHaveNoEffects(): void
    {
        $this->checkout();
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $handler = new \App\Infrastructure\Finance\HostedCheckoutMessageHandler(new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider), new \Symfony\Component\Clock\MockClock('2026-10-10T11:00:01Z'));
        foreach (['{', '[]', '{"operation_id":12}', '{"operation_id":"invalid"}'] as $payload) {
            try {
                $handler(new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000098', 'effect-invalid', 'checkout', '00000000-0000-0000-0000-000000000099', 1, 'checkout.payment_prepared', 1, $payload, new \DateTimeImmutable('2026-10-10T11:00:00Z')));
                self::fail('Malformed payment message accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $provider->keys);
            }
        }
        try {
            $handler(new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000098', 'effect-invalid', 'checkout', '00000000-0000-0000-0000-000000000099', 1, 'checkout.payment_prepared', 2, '{}', new \DateTimeImmutable('2026-10-10T11:00:00Z')));
            self::fail('Unsupported payment protocol accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
        }
    }

    public function testLateWorkerCannotOverwriteTheNewerFencedProviderResult(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $other = \Doctrine\DBAL\DriverManager::getConnection($this->database->getParams());
        $second = new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($other, 'test:platform'), $provider);
        $provider->onCreation = function () use ($authorization, $second, $provider): void {
            $provider->onCreation = null;
            $second->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:02Z'), 'worker-two');
        };
        try {
            $first = new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider);
            try {
                $first->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'worker-one');
                self::fail('Late worker persisted with an obsolete fencing generation.');
            } catch (\DomainException) {
                self::assertSame(2, $this->database->fetchOne('SELECT fencing_generation FROM financial_operation_attempts'));
                self::assertSame('succeeded', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
                self::assertSame('cs_hosted', $this->database->fetchOne('SELECT session_ref FROM payment_attempts'));
                self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
                self::assertSame(['checkout-payment:'.$authorization->operationId, 'checkout-payment:'.$authorization->operationId], $provider->keys);
            }
        } finally {
            $other->close();
        }
    }

    public function testDefinitiveFailedAttemptCannotBeBlindlyReplacedByQueueRedelivery(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $store = new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform');
        $store->prepare($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'worker-one');
        $this->database->update('financial_operation_attempts', ['state' => 'failed', 'resolved_at' => '2026-10-10T11:00:02Z'], ['operation_id' => $authorization->operationId]);
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        try {
            (new \App\Application\Finance\SubmitHostedCheckout($store, $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:03Z'), 'worker-two');
            self::fail('Redelivery replaced the original terminal attempt.');
        } catch (\DomainException) {
            self::assertSame([], $provider->keys);
            self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
            self::assertSame('failed', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
        }
    }

    public function testFailedReconciliationIsCountedAndNeverStopsTheBatch(): void
    {
        $store = new class implements \App\Application\Finance\CheckoutReconciliationStore {
            public function due(\DateTimeImmutable $now, int $limit): array
            {
                return ['00000000-0000-0000-0000-0000000000c1'];
            }

            public function claim(string $checkoutId, \DateTimeImmutable $now, string $worker, ?string $operationId = null): \App\Application\Finance\CheckoutReconciliation
            {
                throw new \DomainException('Checkout reconciliation is unavailable.');
            }

            public function finish(\App\Application\Finance\CheckoutReconciliation $claim, \App\Domain\Finance\HostedPaymentState $state, \DateTimeImmutable $now): void
            {
                throw new \DomainException('Checkout reconciliation is unavailable.');
            }
        };
        $reconcile = new \App\Application\Finance\ReconcileCheckout($store, new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider());

        self::assertSame(['reconciled' => 0, 'failed' => 1], $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'scheduler', 10));
    }

    public function testConfirmedExpiredOriginalPaymentReleasesEveryReservationWithoutPurchase(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $provider->lifecycle = \App\Domain\Finance\HostedPaymentState::Closed;
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Closed, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'reconciliation'));
        self::assertSame('cancelled', $this->database->fetchOne('SELECT state FROM checkouts'));
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertNull($this->database->fetchOne('SELECT reserved_checkout_id FROM buyer_race_guards'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
        self::assertSame(['cs_hosted'], $provider->inspected);
    }

    public function testDeadlineExpiresOriginalOpenSessionBeforeReleasingProtection(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $provider->onExpiration = function (): void {
            self::assertFalse($this->database->isTransactionActive());
            self::assertNotNull($this->database->fetchOne('SELECT cancellation_requested_at FROM checkouts'));
            self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        };
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Closed, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'expiry-worker'));
        self::assertSame(['checkout-expire:'.$authorization->checkoutId], $provider->expirations);
        self::assertSame(['cs_hosted', 'cs_hosted'], $provider->inspected);
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
    }

    public function testLostExpirationResponseRetainsProtectionAndReconcilesOriginalSession(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $provider->onExpiration = static function (): void { throw new \RuntimeException('Response lost'); };
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Unknown, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'first'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame($authorization->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
        self::assertNull($this->database->fetchOne('SELECT lease_until FROM checkouts'));
        $provider->onExpiration = null;
        $provider->lifecycle = \App\Domain\Finance\HostedPaymentState::Closed;
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Closed, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:01Z'), 'second'));
        self::assertSame(['checkout-expire:'.$authorization->checkoutId], $provider->expirations);
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
    }

    public function testNeverSubmittedCheckoutCanReleaseAtDeadlineWithoutProviderMutation(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Closed, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'expiry'));
        self::assertSame([], $provider->keys);
        self::assertSame([], $provider->inspected);
        self::assertSame([], $provider->expirations);
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertNull($this->database->fetchOne('SELECT reserved_checkout_id FROM buyer_race_guards'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedPaymentStates')]
    public function testProcessingPaidAndUnknownOriginalPaymentsStayProtectedAfterDeadline(\App\Domain\Finance\HostedPaymentState $state): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $provider->lifecycle = $state;
        $provider->onInspection = function (): void { self::assertFalse($this->database->isTransactionActive()); };
        self::assertSame($state, (new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider))->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:30:00Z'), 'reconciler'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame($authorization->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
        self::assertSame([], $provider->expirations);
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
    }

    /** @return iterable<array{\App\Domain\Finance\HostedPaymentState}> */
    public static function protectedPaymentStates(): iterable
    {
        yield [\App\Domain\Finance\HostedPaymentState::Processing];
        yield [\App\Domain\Finance\HostedPaymentState::Paid];
        yield [\App\Domain\Finance\HostedPaymentState::Unknown];
    }

    public function testOpenSessionBeforeDeadlineAndUnknownSubmissionAfterDeadlineAreNotReleased(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $submission = new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider);
        $submission->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Open, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:14:59Z'), 'early'));
        self::assertSame([], $provider->expirations);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM checkouts WHERE cancellation_requested_at IS NOT NULL'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        $this->database->update('payment_attempts', ['session_ref' => null, 'state' => 'unknown'], ['checkout_id' => $authorization->checkoutId]);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Unknown, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'late'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame(['cs_hosted'], $provider->inspected);
    }

    public function testLateReconciliationWorkerCannotReleaseNewerProcessingResult(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $other = \Doctrine\DBAL\DriverManager::getConnection($this->database->getParams());
        $nextProvider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $nextProvider->lifecycle = \App\Domain\Finance\HostedPaymentState::Processing;
        $next = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($other, 'test:platform'), $nextProvider);
        $provider->lifecycle = \App\Domain\Finance\HostedPaymentState::Closed;
        $provider->onInspection = function () use ($authorization, $next): void {
            try {
                $next->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:01Z'), 'other-early');
                self::fail('Active reconciliation lease was bypassed.');
            } catch (\DomainException) {
                self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
            }
            $next->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:16:01Z'), 'other-late');
        };
        try {
            try {
                (new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider))->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'original');
                self::fail('Obsolete worker released a processing payment.');
            } catch (\DomainException) {
                self::assertSame('processing', $this->database->fetchOne('SELECT state FROM checkouts'));
                self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
                self::assertSame(2, $this->database->fetchOne('SELECT fencing_generation FROM checkouts'));
                self::assertSame($authorization->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
            }
        } finally {
            $other->close();
        }
    }

    public function testConfirmedClosureReleasesTheWholeMultiRaceCheckoutAtomically(): void
    {
        $checkout = $this->checkout();
        $this->addSecondRaceToCart();
        $authorization = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass(), '00000000-0000-0000-0000-000000000063' => new \stdClass()], [self::RACE => 'transfer-v1', '00000000-0000-0000-0000-000000000005' => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $provider->lifecycle = \App\Domain\Finance\HostedPaymentState::Closed;
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'all-items');
        self::assertSame(2, $this->database->fetchOne("SELECT COUNT(*) FROM checkout_items WHERE protection_state='released' AND reconciliation_state='resolved' AND released_at IS NOT NULL"));
        self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM buyer_race_guards WHERE reserved_checkout_id IS NULL'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
        try {
            $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:01Z'), 'duplicate');
            self::fail('Terminal checkout was reconciled again.');
        } catch (\DomainException) {
            self::assertSame(['cs_hosted'], $provider->inspected);
        }
    }

    public function testReconciliationCannotInspectAnotherProviderScope(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        try {
            (new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:other'), $provider))->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'wrong-scope');
            self::fail('Other provider scope was accepted.');
        } catch (\DomainException) {
            self::assertSame([], $provider->inspected);
            self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
            self::assertSame(0, $this->database->fetchOne('SELECT fencing_generation FROM checkouts'));
        }
    }

    public function testUnconfiguredPaymentProviderCannotReconcileAndKeepsProtection(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), new \App\Infrastructure\Finance\UnconfiguredHostedCheckoutProvider());
        try {
            $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'reconciler');
            self::fail('Unconfigured payment provider reconciled a checkout.');
        } catch (\RuntimeException $error) {
            self::assertSame('Hosted payment operator configuration is required.', $error->getMessage());
        }
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame(0, $this->database->fetchOne('SELECT fencing_generation FROM checkouts'));
        self::assertSame($authorization->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
    }

    public function testNeverSubmittedCheckoutBeforeDeadlineStillAllowsInitialPayment(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Open, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'early-status'));
        self::assertSame('pending', $this->database->fetchOne('SELECT state FROM checkouts'));
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:02Z'), 'initial-submission');
        self::assertSame(['checkout-payment:'.$authorization->operationId], $provider->keys);
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
    }

    public function testUnknownOriginalSubmissionReconciledBeforeDeadlineKeepsItsReplayKey(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $submission = new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider);
        $provider->failNext = true;
        try {
            $submission->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'original');
        } catch (\RuntimeException) {
            self::assertSame('unknown', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
        }
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Unknown, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:00Z'), 'uncertain-status'));
        $submission->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:01Z'), 'replay');
        self::assertSame(['checkout-payment:'.$authorization->operationId, 'checkout-payment:'.$authorization->operationId], $provider->keys);
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
    }

    public function testProtectedSellerWithdrawalRequestsWholeCheckoutCancellationWithoutUnlocking(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $store = new \App\Infrastructure\Selling\DbalListingStore($this->database, $this->publisher(), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($store->withdraw('00000000-0000-0000-0000-000000000001', self::LISTING, 1, new \DateTimeImmutable('2026-10-10T11:01:00Z')));
        self::assertSame('withdrawn', $this->database->fetchOne('SELECT state FROM resale_listings WHERE id=?', [self::LISTING]));
        self::assertNotNull($this->database->fetchOne('SELECT cancellation_requested_at FROM checkouts'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame($authorization->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM outbox_messages WHERE event_type='checkout.cancellation_requested'"));
        self::assertSame('withdrawn', $this->database->fetchOne('SELECT invalidation_reason FROM availability_events WHERE id=?', [self::EVENT]));
    }

    public function testSellerCancellationExpiresOriginalSessionBeforeInitialDeadline(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $store = new \App\Infrastructure\Selling\DbalListingStore($this->database, $this->publisher(), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($store->withdraw('00000000-0000-0000-0000-000000000001', self::LISTING, 1, new \DateTimeImmutable('2026-10-10T11:01:00Z')));
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Closed, (new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider))->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:01Z'), 'cancellation'));
        self::assertSame(['checkout-expire:'.$authorization->checkoutId], $provider->expirations);
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame('withdrawn', $this->database->fetchOne('SELECT state FROM resale_listings WHERE id=?', [self::LISTING]));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
    }

    public function testLatePaymentQueueDeliveryCannotCreateAfterSellerCancellationRequest(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $store = new \App\Infrastructure\Selling\DbalListingStore($this->database, $this->publisher(), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        $store->withdraw('00000000-0000-0000-0000-000000000001', self::LISTING, 1, new \DateTimeImmutable('2026-10-10T11:01:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        try {
            (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:01Z'), 'late-creation');
            self::fail('Cancellation request permitted a fresh provider mutation.');
        } catch (\DomainException) {
            self::assertSame([], $provider->keys);
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
        }
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Closed, (new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider))->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:02Z'), 'no-attempt-cancellation'));
        self::assertSame([], $provider->expirations);
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
    }

    public function testCancellationOutboxHandlerSafelyRedeliversTheOriginalRequest(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        (new \App\Infrastructure\Selling\DbalListingStore($this->database, $this->publisher(), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)))->withdraw('00000000-0000-0000-0000-000000000001', self::LISTING, 1, new \DateTimeImmutable('2026-10-10T11:01:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $handler = new \App\Infrastructure\Finance\CheckoutCancellationMessageHandler(new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider), new \Symfony\Component\Clock\MockClock('2026-10-10T11:01:01Z'));
        $message = new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000090', 'effect-cancel', 'checkout', $authorization->checkoutId, 2, 'checkout.cancellation_requested', 1, json_encode(['operation_id' => $authorization->operationId], JSON_THROW_ON_ERROR), new \DateTimeImmutable('2026-10-10T11:01:00Z'));
        self::assertTrue($handler->supports('checkout.cancellation_requested'));
        self::assertFalse($handler->supports('checkout.payment_prepared'));
        $handler($message);
        $generation = $this->database->fetchOne('SELECT fencing_generation FROM checkouts');
        $handler($message);
        self::assertSame($generation, $this->database->fetchOne('SELECT fencing_generation FROM checkouts'));
        self::assertSame('cancelled', $this->database->fetchOne('SELECT state FROM checkouts'));
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame([], $provider->keys);
        self::assertSame([], $provider->expirations);
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedPaymentStates')]
    public function testSellerCannotForceReleaseAnInFlightOrPaidCancellation(\App\Domain\Finance\HostedPaymentState $state): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'original');
        $provider->lifecycle = $state;
        $store = new \App\Infrastructure\Selling\DbalListingStore($this->database, $this->publisher(), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($store->withdraw('00000000-0000-0000-0000-000000000001', self::LISTING, 1, new \DateTimeImmutable('2026-10-10T11:01:00Z')));
        self::assertFalse($store->withdraw('00000000-0000-0000-0000-000000000001', self::LISTING, 2, new \DateTimeImmutable('2026-10-10T11:01:01Z')));
        self::assertSame($state, (new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider))->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:02Z'), 'cancellation'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame($authorization->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
        self::assertNotNull($this->database->fetchOne('SELECT cancellation_requested_at FROM checkouts'));
        self::assertSame([], $provider->expirations);
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM outbox_messages WHERE event_type='checkout.cancellation_requested'"));
    }

    public function testInvalidCancellationMessagesCannotTouchOrExpireAnUnrelatedCheckout(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $handler = new \App\Infrastructure\Finance\CheckoutCancellationMessageHandler(new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider), new \Symfony\Component\Clock\MockClock('2026-10-10T11:01:00Z'));
        try {
            $handler(new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000090', 'cancel-version', 'checkout', $authorization->checkoutId, 2, 'checkout.cancellation_requested', 2, '{}', new \DateTimeImmutable('2026-10-10T11:01:00Z')));
            self::fail('Unsupported cancellation payload version accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->database->fetchOne('SELECT fencing_generation FROM checkouts'));
        }
        foreach (['{', '[]', '{"operation_id":12}', '{"operation_id":"invalid"}'] as $payload) {
            try {
                $handler(new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000090', 'cancel-invalid', 'checkout', $authorization->checkoutId, 2, 'checkout.cancellation_requested', 1, $payload, new \DateTimeImmutable('2026-10-10T11:01:00Z')));
                self::fail('Invalid cancellation payload accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $this->database->fetchOne('SELECT fencing_generation FROM checkouts'));
            }
        }
        try {
            $handler(new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000090', 'cancel-unrequested', 'checkout', $authorization->checkoutId, 2, 'checkout.cancellation_requested', 1, json_encode(['operation_id' => $authorization->operationId], JSON_THROW_ON_ERROR), new \DateTimeImmutable('2026-10-10T11:01:00Z')));
            self::fail('Checkout without a durable cancellation request was touched.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT fencing_generation FROM checkouts'));
        }
        (new \App\Infrastructure\Selling\DbalListingStore($this->database, $this->publisher(), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database)))->withdraw('00000000-0000-0000-0000-000000000001', self::LISTING, 1, new \DateTimeImmutable('2026-10-10T11:00:59Z'));
        try {
            $handler(new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000090', 'cancel-unrelated-operation', 'checkout', $authorization->checkoutId, 2, 'checkout.cancellation_requested', 1, '{"operation_id":"00000000-0000-0000-0000-000000000099"}', new \DateTimeImmutable('2026-10-10T11:01:00Z')));
            self::fail('Unrelated operation cancellation was accepted.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT fencing_generation FROM checkouts'));
        }
        self::assertSame([], $provider->expirations);
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
    }

    public function testConcurrentWithdrawalsOfDifferentRacesCancelOnceWithoutDeadlock(): void
    {
        $checkout = $this->checkout();
        $this->addSecondRaceToCart();
        $authorization = $checkout->authorize(self::MEMBER, [self::LISTING => new \stdClass(), '00000000-0000-0000-0000-000000000063' => new \stdClass()], [self::RACE => 'transfer-v1', '00000000-0000-0000-0000-000000000005' => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $code = <<<'CODE'
require 'vendor/autoload.php';
$database=\Doctrine\DBAL\DriverManager::getConnection(json_decode(getenv('CHECKOUT_TEST_PARAMS'),true,512,JSON_THROW_ON_ERROR));
$database->executeQuery("SELECT set_config('application_name',?,false)",[getenv('CHECKOUT_TEST_NAME')]);
$outbox=new \App\Infrastructure\Operations\DbalOutboxWriter($database);
$availability=new \App\Application\Availability\CaptureAvailability(new \App\Infrastructure\Availability\DbalAvailabilitySnapshotStore($database),$outbox);
$store=new \App\Infrastructure\Selling\DbalListingStore($database,$availability,'test:platform',new \App\Infrastructure\Operations\DbalAuditWriter($database),$outbox);
echo $store->withdraw('00000000-0000-0000-0000-000000000001',$argv[1],1,new \DateTimeImmutable('2026-10-10T11:01:00Z')) ? 'withdrawn' : 'denied';
CODE;
        $name = 'withdraw_'.bin2hex(random_bytes(6));
        $environment = ['CHECKOUT_TEST_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR), 'CHECKOUT_TEST_NAME' => $name];
        $first = new \Symfony\Component\Process\Process(['php', '-r', $code, self::LISTING], dirname(__DIR__, 3), $environment);
        $second = new \Symfony\Component\Process\Process(['php', '-r', $code, '00000000-0000-0000-0000-000000000063'], dirname(__DIR__, 3), $environment);
        $this->database->beginTransaction();
        $this->database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [self::RACE]);
        try {
            $first->start();
            $deadline = microtime(true) + 10;
            do {
                $this->database->executeQuery('SELECT pg_stat_clear_snapshot()');
                $blocked = $this->database->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$name]);
                if (1 === $blocked) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(1, $blocked);
            $second->start();
            $deadline = microtime(true) + 10;
            do {
                $this->database->executeQuery('SELECT pg_stat_clear_snapshot()');
                $blocked = $this->database->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$name]);
                if (2 === $blocked) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both original-item and higher-race withdrawals must reach the shared sorted lock.');
            $this->database->commit();
            self::assertSame(0, $first->wait(), $first->getErrorOutput());
            self::assertSame(0, $second->wait(), $second->getErrorOutput());
            self::assertSame('withdrawn', $first->getOutput());
            self::assertSame('withdrawn', $second->getOutput());
            self::assertSame(2, $this->database->fetchOne("SELECT COUNT(*) FROM checkout_items WHERE protection_state='protected'"));
            self::assertSame(2, $this->database->fetchOne("SELECT COUNT(*) FROM resale_listings WHERE state='withdrawn'"));
            self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM outbox_messages WHERE event_type='checkout.cancellation_requested'"));
            self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
            $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
            (new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider))->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:01Z'), 'whole-cancellation');
            self::assertSame(2, $this->database->fetchOne("SELECT COUNT(*) FROM checkout_items WHERE protection_state='released'"));
            self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM buyer_race_guards WHERE reserved_checkout_id IS NULL'));
        } finally {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
            $first->stop();
            $second->stop();
        }
    }

    public function testStringifiedDriverFactsCannotPrepareAProviderPayment(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $misconfigured = $this->stringifiedDatabase();
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        try {
            try {
                (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($misconfigured, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'bad-driver');
                self::fail('Stringified money facts prepared a payment.');
            } catch (\UnexpectedValueException) {
                self::assertSame([], $provider->keys);
                self::assertSame([], $provider->retrieved);
                self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
                self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM payment_attempts'));
                self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
                self::assertSame('pending', $this->database->fetchOne('SELECT state FROM checkouts'));
                self::assertNull($this->database->fetchOne('SELECT lease_until FROM checkouts'));
                self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
            }
        } finally {
            $misconfigured->close();
        }
    }

    public function testStringifiedDriverFactsCannotClaimOrReleaseAnOriginalPayment(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'original');
        $misconfigured = $this->stringifiedDatabase();
        try {
            try {
                (new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($misconfigured, 'test:platform'), $provider))->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'bad-driver');
                self::fail('Stringified journal facts authorized reconciliation.');
            } catch (\UnexpectedValueException) {
                self::assertSame([], $provider->inspected);
                self::assertSame([], $provider->expirations);
                self::assertSame(0, $this->database->fetchOne('SELECT fencing_generation FROM checkouts'));
                self::assertNull($this->database->fetchOne('SELECT lease_until FROM checkouts'));
                self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM checkouts WHERE cancellation_requested_at IS NOT NULL'));
                self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
                self::assertSame($authorization->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
                self::assertSame('succeeded', $this->database->fetchOne('SELECT state FROM financial_operation_attempts'));
                self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
            }
        } finally {
            $misconfigured->close();
        }
    }

    public function testStringifiedDriverCannotAuthorizePartialCheckoutFacts(): void
    {
        $this->checkout();
        $misconfigured = $this->stringifiedDatabase();
        $provider = new \App\Tests\Integration\Finance\ControlledSellerProvider();
        $provider->status = \App\Domain\Finance\SellerReadiness::Ready;
        $authorize = new AuthorizeCheckout(new DbalCheckoutAuthorizationStore($misconfigured), new \App\Application\Availability\FindOfferAccess(new \App\Infrastructure\Availability\DbalAvailabilityAccessStore($this->database)), new \App\Infrastructure\Operations\DbalTransactionManager($misconfigured), new \App\Infrastructure\Operations\DbalOutboxWriter($misconfigured), new \App\Domain\Finance\FeePolicy(), 'test:platform', 'fees-v1', ['success_url' => 'https://runnerbib.test/success', 'cancel_url' => 'https://runnerbib.test/cancel', 'api_version' => '2026-09-30.endive', 'automatic_tax' => false], 'transfer-v1');
        try {
            try {
                $authorize->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
                self::fail('Misconfigured cart facts created a checkout.');
            } catch (\DomainException) {
                foreach (['checkouts', 'checkout_items', 'buyer_race_guards', 'financial_operations', 'administrative_audit'] as $table) {
                    self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM '.$table));
                }
                self::assertSame(0, $provider->accountsCreated);
                self::assertSame(0, $provider->linksCreated);
                self::assertFalse($misconfigured->isTransactionActive());
            }
        } finally {
            $misconfigured->close();
        }
    }

    public function testPrivacyBuyerCancellationRequestsOnlyOwnedProtectedCheckouts(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $requests = new \App\Infrastructure\Finance\DbalCheckoutCancellationRequests($this->database, new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        $this->database->beginTransaction();
        $this->database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [self::RACE]);
        $this->database->fetchOne('SELECT id FROM accounts WHERE id=? FOR UPDATE', [self::MEMBER]);
        try {
            $requests->requestBuyer(self::LATE, new \DateTimeImmutable('2026-10-10T11:01:00Z'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM checkouts WHERE cancellation_requested_at IS NOT NULL'));
            $requests->requestBuyer(self::MEMBER, new \DateTimeImmutable('2026-10-10T11:01:00Z'));
            $requested = $this->database->fetchOne('SELECT cancellation_requested_at FROM checkouts');
            self::assertNotNull($requested);
            $requests->requestBuyer(self::MEMBER, new \DateTimeImmutable('2026-10-10T11:02:00Z'));
            self::assertSame($requested, $this->database->fetchOne('SELECT cancellation_requested_at FROM checkouts'));
            $this->database->commit();
        } finally {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
        }
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame($authorization->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM outbox_messages WHERE event_type='checkout.cancellation_requested'"));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operation_attempts'));
    }

    public function testBuyerCancellationRequiresOwningTransactionAndRejectsStringifiedContext(): void
    {
        $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $request = new \App\Infrastructure\Finance\DbalCheckoutCancellationRequests($this->database, new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        try {
            $request->requestBuyer(self::MEMBER, new \DateTimeImmutable('2026-10-10T11:01:00Z'));
            self::fail('Unowned transaction mutated a checkout.');
        } catch (\LogicException) {
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM checkouts WHERE cancellation_requested_at IS NOT NULL'));
        }
        $misconfigured = $this->stringifiedDatabase();
        $misconfigured->beginTransaction();
        $misconfigured->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [self::RACE]);
        try {
            try {
                (new \App\Infrastructure\Finance\DbalCheckoutCancellationRequests($misconfigured, new \App\Infrastructure\Operations\DbalOutboxWriter($misconfigured)))->requestBuyer(self::MEMBER, new \DateTimeImmutable('2026-10-10T11:01:00Z'));
                self::fail('Stringified cancellation context was accepted.');
            } catch (\UnexpectedValueException) {
                self::assertNull($misconfigured->fetchOne('SELECT cancellation_requested_at FROM checkouts'));
            }
        } finally {
            $misconfigured->rollBack();
            $misconfigured->close();
        }
        self::assertSame(0, $this->database->fetchOne("SELECT COUNT(*) FROM outbox_messages WHERE event_type='checkout.cancellation_requested'"));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
    }

    public function testDueReconciliationSkipsFutureCheckoutsAndClosesNeverSubmittedCheckoutAtItsDeadline(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(0, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:14:59Z'), 'scheduler', 1)['reconciled']);
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame(1, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'scheduler', 1)['reconciled']);
        self::assertSame('cancelled', $this->database->fetchOne('SELECT state FROM checkouts WHERE id=?', [$authorization->checkoutId]));
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertNull($this->database->fetchOne('SELECT reserved_checkout_id FROM buyer_race_guards'));
        self::assertSame([], $provider->inspected);
        self::assertSame([], $provider->expirations);
        self::assertSame(0, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:16:00Z'), 'scheduler', 1)['reconciled']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedPaymentStates')]
    public function testDueReconciliationRetainsUnresolvedProtectionAndWaitsAMinuteBeforeRechecking(\App\Domain\Finance\HostedPaymentState $state): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $provider->lifecycle = $state;
        $provider->onInspection = function (): void { self::assertFalse($this->database->isTransactionActive()); };
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(1, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'scheduler', 1)['reconciled']);
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
        self::assertSame($authorization->checkoutId, $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
        self::assertSame('2026-10-10 11:15:00+00', $this->database->fetchOne('SELECT last_reconciled_at FROM payment_attempts'));
        self::assertSame(0, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:15:59Z'), 'scheduler', 1)['reconciled']);
        self::assertSame(1, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:16:00Z'), 'scheduler', 1)['reconciled']);
        self::assertSame(['cs_hosted', 'cs_hosted'], $provider->inspected);
        self::assertSame([], $provider->expirations);
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
    }

    public function testDueReconciliationFindsEarlyWithdrawalAndSkipsAnotherProviderScopeAndLiveLease(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $store = new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform');
        $reconcile = new \App\Application\Finance\ReconcileCheckout($store, $provider);
        $listing = new \App\Infrastructure\Selling\DbalListingStore($this->database, $this->publisher(), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($listing->withdraw('00000000-0000-0000-0000-000000000001', self::LISTING, 1, new \DateTimeImmutable('2026-10-10T11:01:00Z')));
        self::assertSame([], (new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'another:platform'))->due(new \DateTimeImmutable('2026-10-10T11:01:00Z'), 1));
        $store->claim($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:00Z'), 'crashed-worker');
        self::assertSame(0, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:01:59Z'), 'scheduler', 1)['reconciled']);
        self::assertSame(1, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:02:00Z'), 'scheduler', 1)['reconciled']);
        self::assertSame('cancelled', $this->database->fetchOne('SELECT state FROM checkouts'));
        self::assertSame('withdrawn', $this->database->fetchOne('SELECT state FROM resale_listings WHERE id=?', [self::LISTING]));
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
    }

    public function testDueReconciliationBoundDoesNotLetAnUnresolvedPaymentStarveTheNextCheckout(): void
    {
        $authorize = $this->checkout();
        $first = $authorize->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($first->operationId, $first->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        (new \App\Infrastructure\Checkout\DbalCartStore($this->database))->remove(self::MEMBER, self::LISTING);
        $this->addSecondRaceToCart();
        $second = $authorize->authorize(self::MEMBER, ['00000000-0000-0000-0000-000000000063' => new \stdClass()], ['00000000-0000-0000-0000-000000000005' => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:01Z'));
        $provider->lifecycle = \App\Domain\Finance\HostedPaymentState::Processing;
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(1, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'scheduler', 1)['reconciled']);
        self::assertSame('pending', $this->database->fetchOne('SELECT state FROM checkouts WHERE id=?', [$second->checkoutId]));
        self::assertSame(1, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:16:01Z'), 'scheduler', 1)['reconciled']);
        self::assertSame('cancelled', $this->database->fetchOne('SELECT state FROM checkouts WHERE id=?', [$second->checkoutId]));
        self::assertSame('processing', $this->database->fetchOne('SELECT state FROM checkouts WHERE id=?', [$first->checkoutId]));
        self::assertSame(['cs_hosted'], $provider->inspected);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReconciliationLimits')]
    public function testDueReconciliationRejectsInvalidLimits(int $limit): void
    {
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        $this->expectException(\InvalidArgumentException::class);
        $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'scheduler', $limit);
    }

    /** @return iterable<array{int}> */
    public static function invalidReconciliationLimits(): iterable
    {
        yield [0];
        yield [1001];
    }

    public function testDueReconciliationDoesNotLetAnEarlyStatusCheckPostponeTheInitialDeadline(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Open, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:14:59Z'), 'status-check'));
        self::assertSame(1, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:15:00Z'), 'scheduler', 1)['reconciled']);
        self::assertSame(['checkout-expire:'.$authorization->checkoutId], $provider->expirations);
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
    }

    public function testDueReconciliationDoesNotDelayANewWithdrawalAfterARecentStatusCheck(): void
    {
        $authorization = $this->checkout()->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $provider = new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($authorization->operationId, $authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:00:01Z'), 'submission');
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Open, $reconcile->reconcile($authorization->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:00Z'), 'status-check'));
        $listing = new \App\Infrastructure\Selling\DbalListingStore($this->database, $this->publisher(), 'test:platform', new \App\Infrastructure\Operations\DbalAuditWriter($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database));
        self::assertTrue($listing->withdraw('00000000-0000-0000-0000-000000000001', self::LISTING, 1, new \DateTimeImmutable('2026-10-10T11:01:01Z')));
        self::assertSame(1, $reconcile->reconcileDue(new \DateTimeImmutable('2026-10-10T11:01:01Z'), 'scheduler', 1)['reconciled']);
        self::assertSame('released', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
    }

    public function testDueReconciliationCommandProcessesMoreThanOneCheckoutWithTheExistingUseCase(): void
    {
        $authorize = $this->checkout();
        $authorize->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        (new \App\Infrastructure\Checkout\DbalCartStore($this->database))->remove(self::MEMBER, self::LISTING);
        $this->addSecondRaceToCart();
        $authorize->authorize(self::MEMBER, ['00000000-0000-0000-0000-000000000063' => new \stdClass()], ['00000000-0000-0000-0000-000000000005' => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:01Z'));
        $reconcile = new \App\Application\Finance\ReconcileCheckout(new \App\Infrastructure\Finance\DbalCheckoutReconciliationStore($this->database, 'test:platform'), new \App\Tests\Integration\Finance\ControlledHostedCheckoutProvider());
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \App\Presentation\Console\ReconcileCheckoutsCommand($reconcile));
        self::assertSame(0, $tester->execute(['--limit' => '2']));
        self::assertSame("Reconciled checkouts: 2\n", $tester->getDisplay());
        self::assertSame(2, $this->database->fetchOne("SELECT COUNT(*) FROM checkouts WHERE state='cancelled'"));
        self::assertSame(2, $this->database->fetchOne("SELECT COUNT(*) FROM checkout_items WHERE protection_state='released'"));
    }

    private function addSecondRaceToCart(): void
    {
        $this->database->executeStatement("INSERT INTO bib_entitlements(id,race_id,bib_number,source_kind,current_owner_account_id) VALUES ('00000000-0000-0000-0000-000000000061','00000000-0000-0000-0000-000000000005','00456','document','00000000-0000-0000-0000-000000000001')");
        $this->database->executeStatement("INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,entitlement_id,source_kind,private_object_id,submitted_at,decision,reviewed_version,reviewer_id,reviewed_at) SELECT '00000000-0000-0000-0000-000000000062',submitted_by,'00000000-0000-0000-0000-000000000005','00456','00000000-0000-0000-0000-000000000061',source_kind,private_object_id,submitted_at,decision,reviewed_version,reviewer_id,reviewed_at FROM ownership_proofs WHERE id='00000000-0000-0000-0000-000000000008'");
        $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,entitlement_id,seller_id,proof_id,approved_proof_version,price_cents,state,published_at) VALUES ('00000000-0000-0000-0000-000000000063','00000000-0000-0000-0000-000000000005','00000000-0000-0000-0000-000000000061','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000062',1,20000,'published','2026-01-01Z')");
        $this->database->beginTransaction();
        $this->publisher()->publish('00000000-0000-0000-0000-000000000064', '00000000-0000-0000-0000-000000000063', new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $this->database->insert('cart_items', ['buyer_id' => self::MEMBER, 'listing_id' => '00000000-0000-0000-0000-000000000063', 'added_at' => '2026-10-10T11:00:00Z']);
    }

    /** @param array<string,mixed>|null $configuration */
    private function checkout(string $feeVersion = 'fees-v1', ?array $configuration = null, \App\Domain\Finance\SellerReadiness $readiness = \App\Domain\Finance\SellerReadiness::Ready): AuthorizeCheckout
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test', 'display_name' => 'Buyer']);
        $this->database->insert('seller_accounts', ['id' => '00000000-0000-0000-0000-000000000050', 'seller_id' => '00000000-0000-0000-0000-000000000001', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'connected_account_ref' => 'acct_recipient', 'readiness_state' => $readiness->value, 'last_reconciled_at' => '2026-10-10T10:00:00Z']);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $this->database->insert('cart_items', ['buyer_id' => self::MEMBER, 'listing_id' => self::LISTING, 'added_at' => '2026-10-10T11:00:00Z']);

        return new AuthorizeCheckout(new DbalCheckoutAuthorizationStore($this->database), new \App\Application\Availability\FindOfferAccess(new \App\Infrastructure\Availability\DbalAvailabilityAccessStore($this->database)), new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database), new \App\Domain\Finance\FeePolicy(), 'test:platform', $feeVersion, $configuration ?? ['success_url' => 'https://runnerbib.test/success', 'cancel_url' => 'https://runnerbib.test/cancel', 'api_version' => '2026-09-30.endive', 'automatic_tax' => false], 'transfer-v1');
    }
}
