<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Application\Finance\ProcessProviderEvents;
use App\Application\Finance\ReconcileCheckout;
use App\Domain\Finance\HostedPaymentState;
use App\Infrastructure\Finance\DbalCheckoutReconciliationStore;
use App\Infrastructure\Finance\DbalProviderEventInbox;
use App\Tests\Integration\Availability\AvailabilityTestCase;

final class ProcessProviderEventsTest extends AvailabilityTestCase
{
    public function testProviderEventRefreshesTheCheckoutAndIsCompleted(): void
    {
        $checkout = $this->checkout();
        $provider = new ControlledHostedCheckoutProvider();
        (new \App\Application\Finance\SubmitHostedCheckout(new \App\Infrastructure\Finance\DbalPaymentSubmissionStore($this->database, 'test:platform'), $provider))->submit($checkout->operationId, $checkout->checkoutId, new \DateTimeImmutable('2026-10-10T11:01:00Z'), 'submission');
        $provider->lifecycle = HostedPaymentState::Paid;
        $inbox = new DbalProviderEventInbox($this->database);
        $this->database->insert('provider_event_inbox', ['id' => '00000000-0000-0000-0000-0000000000d1', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'event_ref' => 'evt_checkout', 'received_at' => '2026-10-10T11:05:00Z', 'authenticated_at' => '2026-10-10T11:05:00Z', 'payload_version' => 1, 'protected_payload' => json_encode(['id' => 'evt_checkout', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_hosted', 'metadata' => ['checkout_id' => $checkout->checkoutId]]]], JSON_THROW_ON_ERROR), 'processing_state' => 'pending']);

        $result = (new ProcessProviderEvents($inbox, new ReconcileCheckout(new DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider)))->processDue(new \DateTimeImmutable('2026-10-10T11:06:00Z'), 'worker-1', 10);

        self::assertSame(['processed' => 1, 'ignored' => 0, 'failed' => 0], $result);
        self::assertSame('completed', $this->database->fetchOne('SELECT processing_state FROM provider_event_inbox'));
        self::assertNotNull($this->database->fetchOne('SELECT processed_at FROM provider_event_inbox'));
        self::assertSame('processing', $this->database->fetchOne('SELECT state FROM checkouts'));
        self::assertSame(['cs_hosted'], $provider->inspected);
    }

    public function testEventWithoutCheckoutIsCompletedWithoutReconciliation(): void
    {
        $provider = new ControlledHostedCheckoutProvider();
        $inbox = new DbalProviderEventInbox($this->database);
        $this->database->insert('provider_event_inbox', ['id' => '00000000-0000-0000-0000-0000000000d2', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'event_ref' => 'evt_account', 'received_at' => '2026-10-10T11:05:00Z', 'authenticated_at' => '2026-10-10T11:05:00Z', 'payload_version' => 1, 'protected_payload' => json_encode(['id' => 'evt_account', 'type' => 'account.updated', 'data' => ['object' => ['id' => 'acct_1']]], JSON_THROW_ON_ERROR), 'processing_state' => 'pending']);

        $result = (new ProcessProviderEvents($inbox, new ReconcileCheckout(new DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider)))->processDue(new \DateTimeImmutable('2026-10-10T11:06:00Z'), 'worker-1', 10);

        self::assertSame(['processed' => 0, 'ignored' => 1, 'failed' => 0], $result);
        self::assertSame('completed', $this->database->fetchOne('SELECT processing_state FROM provider_event_inbox'));
        self::assertSame([], $provider->inspected);
    }

    public function testFailedReconciliationKeepsTheEventForTheNextCycle(): void
    {
        $provider = new ControlledHostedCheckoutProvider();
        $provider->onInspection = static function (): void { throw new \RuntimeException('Provider unavailable'); };
        $inbox = new DbalProviderEventInbox($this->database);
        $this->database->insert('provider_event_inbox', ['id' => '00000000-0000-0000-0000-0000000000d3', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'event_ref' => 'evt_retry', 'received_at' => '2026-10-10T11:05:00Z', 'authenticated_at' => '2026-10-10T11:05:00Z', 'payload_version' => 1, 'protected_payload' => json_encode(['id' => 'evt_retry', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_hosted', 'metadata' => ['checkout_id' => '00000000-0000-0000-0000-0000000000d4']]]], JSON_THROW_ON_ERROR), 'processing_state' => 'pending']);

        $result = (new ProcessProviderEvents($inbox, new ReconcileCheckout(new DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider)))->processDue(new \DateTimeImmutable('2026-10-10T11:06:00Z'), 'worker-1', 10);

        self::assertSame(['processed' => 0, 'ignored' => 0, 'failed' => 1], $result);
        self::assertSame('failed', $this->database->fetchOne('SELECT processing_state FROM provider_event_inbox'));
        self::assertNotNull($this->database->fetchOne('SELECT next_attempt_at FROM provider_event_inbox'));
    }

    public function testInvalidLimitIsRefused(): void
    {
        $inbox = new DbalProviderEventInbox($this->database);
        $provider = new ControlledHostedCheckoutProvider();
        $this->expectException(\InvalidArgumentException::class);
        (new ProcessProviderEvents($inbox, new ReconcileCheckout(new DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider)))->processDue(new \DateTimeImmutable('2026-10-10T11:06:00Z'), 'worker-1', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validLimits')]
    public function testTheInclusiveBatchLimitsCanProcessAnEmptyInbox(int $limit): void
    {
        $provider = new ControlledHostedCheckoutProvider();
        $events = new ProcessProviderEvents(new DbalProviderEventInbox($this->database), new ReconcileCheckout(new DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider));
        self::assertSame(['processed' => 0, 'ignored' => 0, 'failed' => 0], $events->processDue(new \DateTimeImmutable('2026-10-10Z'), 'worker', $limit));
    }

    /** @return iterable<array{int}> */
    public static function validLimits(): iterable
    {
        yield [1];
        yield [1000];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providerFailures')]
    public function testAnIgnoredOrFailedEventDoesNotStopTheRestOfTheBatch(bool $configurationFailure): void
    {
        $provider = new ControlledHostedCheckoutProvider();
        $provider->failConfiguration = $configurationFailure;
        foreach ([null, '00000000-0000-0000-0000-000000000099', null, '00000000-0000-0000-0000-000000000099'] as $index => $checkout) {
            $this->database->insert('provider_event_inbox', ['id' => sprintf('00000000-0000-0000-0000-%012d', $index + 1), 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'event_ref' => 'evt_'.$index, 'processing_state' => 'pending', 'received_at' => '2026-10-10T11:05:00Z', 'authenticated_at' => '2026-10-10T11:05:00Z', 'payload_version' => 1, 'protected_payload' => json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['metadata' => ['checkout_id' => $checkout]]]], JSON_THROW_ON_ERROR)]);
        }
        $events = new ProcessProviderEvents(new DbalProviderEventInbox($this->database), new ReconcileCheckout(new DbalCheckoutReconciliationStore($this->database, 'test:platform'), $provider));
        self::assertSame(['processed' => 0, 'ignored' => 2, 'failed' => 2], $events->processDue(new \DateTimeImmutable('2026-10-10T11:06:00Z'), 'worker', 10));
        self::assertSame(2, $this->database->fetchOne("SELECT COUNT(*) FROM provider_event_inbox WHERE processing_state='completed'"));
        self::assertSame(2, $this->database->fetchOne("SELECT COUNT(*) FROM provider_event_inbox WHERE processing_state='failed'"));
    }

    /** @return iterable<array{bool}> */
    public static function providerFailures(): iterable
    {
        yield [false];
        yield [true];
    }

    private function checkout(): \App\Application\Checkout\CheckoutAuthorizationView
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test', 'display_name' => 'Buyer']);
        $this->database->insert('seller_accounts', ['id' => '00000000-0000-0000-0000-000000000050', 'seller_id' => '00000000-0000-0000-0000-000000000001', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'connected_account_ref' => 'acct_recipient', 'readiness_state' => 'ready']);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $this->database->insert('cart_items', ['buyer_id' => self::MEMBER, 'listing_id' => self::LISTING, 'added_at' => '2026-10-10T11:00:00Z']);
        $authorize = new \App\Application\Checkout\AuthorizeCheckout(new \App\Infrastructure\Checkout\DbalCheckoutAuthorizationStore($this->database), new \App\Application\Availability\FindOfferAccess(new \App\Infrastructure\Availability\DbalAvailabilityAccessStore($this->database)), new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalOutboxWriter($this->database), new \App\Domain\Finance\FeePolicy(), 'test:platform', 'fees-v1', ['success_url' => 'https://runnerbib.test/success', 'cancel_url' => 'https://runnerbib.test/cancel', 'api_version' => '2024-06-20', 'automatic_tax' => false], 'transfer-v1');

        return $authorize->authorize(self::MEMBER, [self::LISTING => new \stdClass()], [self::RACE => 'transfer-v1'], new \DateTimeImmutable('2026-10-10T11:00:00Z'));
    }
}
