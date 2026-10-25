<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Tests\Support\IsolatedWebTestCase;
use Stripe\WebhookSignature;

final class ProviderEventInboxTest extends IsolatedWebTestCase
{
    public function testAuthenticatedOriginalPaymentEventIsPersistedBeforeAcknowledgement(): void
    {
        $body = $this->body();
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $signature = WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp());
        $receive = $this->receiver();
        self::assertTrue($receive->receive($body, $signature, $now));
        $row = $this->database->fetchAssociative('SELECT provider,provider_scope,event_ref,authenticated_at,processing_state,protected_payload FROM provider_event_inbox');
        self::assertIsArray($row);
        self::assertSame('stripe', $row['provider']);
        self::assertSame('test:platform', $row['provider_scope']);
        self::assertSame('evt_original', $row['event_ref']);
        self::assertNotNull($row['authenticated_at']);
        self::assertSame('pending', $row['processing_state']);
        self::assertIsString($row['protected_payload']);
        $payload = json_decode($row['protected_payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('checkout.session.completed', $payload['type']);
        self::assertSame('2026-09-30.endive', $payload['api_version']);
        self::assertFalse($payload['livemode']);
        self::assertIsArray($payload['data']);
        self::assertIsArray($payload['data']['object']);
        self::assertSame('cs_original', $payload['data']['object']['id']);
        self::assertSame(10500, $payload['data']['object']['amount_total']);
        self::assertSame('pi_original', $payload['data']['object']['payment_intent']);
        self::assertStringNotContainsString('private@example.test', $row['protected_payload']);
        self::assertStringNotContainsString('cus_private', $row['protected_payload']);
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('expiredSignatures')]
    public function testOldAndFutureSignedRequestsCannotEnterTheInbox(int $offset): void
    {
        $body = $this->body();
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $signature = WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp() + $offset);
        try {
            $this->receiver()->receive($body, $signature, $now);
            self::fail('Outside-tolerance signed request was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
        }
    }

    /** @return iterable<array{int}> */
    public static function expiredSignatures(): iterable
    {
        yield [-301];
        yield [301];
    }

    public function testAuthenticatedLiveEventCannotEnterATestModeInbox(): void
    {
        $body = str_replace('"livemode":false', '"livemode":true', $this->body());
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $signature = WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp());
        try {
            $this->receiver()->receive($body, $signature, $now);
            self::fail('Wrong-mode event entered inbox.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
        }
    }

    public function testUnconfiguredWebhookRefusesAtUseTimeAndCanStillBeConstructed(): void
    {
        $body = $this->body();
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $signature = WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp());
        foreach ([['', 'test:platform'], ['whsec_controlled', ''], ['whsec_controlled', 'unconfigured']] as [$secret,$scope]) {
            $receive = new \App\Application\Finance\ReceiveProviderEvent(new \App\Infrastructure\Finance\StripeProviderEventAuthenticator($secret, $scope, false), new \App\Infrastructure\Finance\DbalProviderEventInbox($this->database));
            try {
                $receive->receive($body, $signature, $now);
                self::fail('Unconfigured webhook accepted a request.');
            } catch (\RuntimeException) {
                self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
            }
        }
    }

    public function testDuplicateDeliveryKeepsTheOriginalInboxPayloadAndReceipt(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $body = $this->body();
        $receive = $this->receiver();
        self::assertTrue($receive->receive($body, WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp()), $now));
        $received = $this->database->fetchOne('SELECT received_at FROM provider_event_inbox');
        $payload = $this->database->fetchOne('SELECT protected_payload FROM provider_event_inbox');
        $later = $now->add(new \DateInterval('PT60S'));
        self::assertFalse($receive->receive($body, WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $later->getTimestamp()), $later));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
        self::assertSame($received, $this->database->fetchOne('SELECT received_at FROM provider_event_inbox'));
        self::assertSame($payload, $this->database->fetchOne('SELECT protected_payload FROM provider_event_inbox'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
    }

    public function testSignatureAndEnvelopeFailuresNeverPersistAnInboxReceipt(): void
    {
        $body = $this->body();
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $signature = WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp());
        foreach ([[$body.' ', $signature], [$body, WebhookSignature::generateSignatureHeader($body, 'whsec_wrong', $now->getTimestamp())], [$body, ''], [$body, 't=1791630000,v0=invalid'], ['{', WebhookSignature::generateSignatureHeader('{', 'whsec_controlled', $now->getTimestamp())], ['[]', WebhookSignature::generateSignatureHeader('[]', 'whsec_controlled', $now->getTimestamp())], ['{}', WebhookSignature::generateSignatureHeader('{}', 'whsec_controlled', $now->getTimestamp())]] as [$raw,$header]) {
            try {
                $this->receiver()->receive($raw, $header, $now);
                self::fail('Invalid request accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
            }
        }
    }

    public function testInboxPersistenceFailureDoesNotAcknowledgeAndCanRetry(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $body = $this->body();
        $signature = WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp());
        $this->database->executeStatement("CREATE FUNCTION controlled_inbox_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Controlled inbox persistence failure'; END $$");
        $this->database->executeStatement('CREATE TRIGGER controlled_inbox_failure BEFORE INSERT ON provider_event_inbox FOR EACH ROW EXECUTE FUNCTION controlled_inbox_failure()');
        try {
            $this->receiver()->receive($body, $signature, $now);
            self::fail('Failed inbox write was acknowledged.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Authenticated provider event could not be committed.', $exception->getMessage());
            self::assertFalse($this->database->isTransactionActive());
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
        }
        $this->database->executeStatement('DROP TRIGGER controlled_inbox_failure ON provider_event_inbox');
        $this->database->executeStatement('DROP FUNCTION controlled_inbox_failure()');
        self::assertTrue($this->receiver()->receive($body, $signature, $now));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
    }

    public function testSignatureRotationAndToleranceBoundariesRemainAuthenticated(): void
    {
        $body = $this->body();
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $signature = WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp() - 300);
        $signature .= ',v1='.str_repeat('0', 64);
        self::assertTrue($this->receiver()->receive($body, $signature, $now));
        self::assertFalse($this->receiver()->receive($body, WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp() + 300), $now));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
    }

    public function testAcknowledgementRequiresAnIndependentCommittedInboxWrite(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $body = $this->body();
        $this->database->beginTransaction();
        try {
            $this->receiver()->receive($body, WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp()), $now);
            self::fail('Uncommitted caller-owned transaction was acknowledged.');
        } catch (\LogicException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
        } finally {
            $this->database->rollBack();
        }
    }

    public function testDuplicateSignedTimestampCannotBypassTheTrustedClockBoundary(): void
    {
        $body = $this->body();
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $signature = WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp()).',t='.$now->getTimestamp();
        try {
            $this->receiver()->receive($body, $signature, $now);
            self::fail('Duplicate timestamp accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
        }
    }

    public function testProviderScopeDeduplicationDoesNotSuppressAnotherPlatformInbox(): void
    {
        $body = $this->body();
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $signature = WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp());
        self::assertTrue($this->receiver()->receive($body, $signature, $now));
        $other = new \App\Application\Finance\ReceiveProviderEvent(new \App\Infrastructure\Finance\StripeProviderEventAuthenticator('whsec_controlled', 'test:other-platform', false), new \App\Infrastructure\Finance\DbalProviderEventInbox($this->database));
        self::assertTrue($other->receive($body, $signature, $now));
        self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
    }

    public function testOriginalExpandedIntentAndChargeFactsExcludePrivateFields(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $body = '{"id":"evt_intent","object":"event","type":"payment_intent.processing","api_version":"2026-09-30.endive","created":1791630000,"livemode":false,"account":"acct_original","data":{"object":{"id":"pi_original","object":"payment_intent","status":"processing","amount":10500,"amount_received":0,"currency":"eur","metadata":{"checkout_id":"checkout-original","operation_id":"operation-original","private_note":"private-metadata"},"receipt_email":"private@example.test","latest_charge":{"id":"ch_original","object":"charge","amount":10500,"currency":"eur","billing_details":{"name":"Private Buyer"}},"payment_intent":null}}}';
        self::assertTrue($this->receiver()->receive($body, WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp()), $now));
        $stored = $this->database->fetchOne('SELECT protected_payload FROM provider_event_inbox');
        self::assertIsString($stored);
        self::assertStringContainsString('acct_original', $stored);
        self::assertStringContainsString('pi_original', $stored);
        self::assertStringContainsString('ch_original', $stored);
        self::assertStringContainsString('processing', $stored);
        foreach (['private@example.test', 'Private Buyer', 'private-metadata'] as $private) {
            self::assertStringNotContainsString($private, $stored);
        }
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
    }

    public function testOutOfOrderUnrelatedEventsDoNotConfirmOrReplaceAPurchase(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $first = str_replace(['evt_original', 'checkout.session.completed'], ['evt_new', 'checkout.session.expired'], $this->body());
        $second = str_replace(['evt_original', 'checkout.session.completed'], ['evt_old', 'customer.created'], $this->body());
        foreach ([$first, $second] as $body) {
            self::assertTrue($this->receiver()->receive($body, WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp()), $now));
        }
        self::assertSame(2, $this->database->fetchOne("SELECT COUNT(*) FROM provider_event_inbox WHERE processing_state='pending'"));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM purchases'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM financial_operations'));
    }

    public function testMalformedSignaturePartsAreRejectedWithoutVendorParserWarnings(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $body = $this->body();
        foreach (['t', 't='.$now->getTimestamp().',v1'] as $signature) {
            try {
                $this->receiver()->receive($body, $signature, $now);
                self::fail('Malformed signature accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
            }
        }
    }

    public function testSignedInvalidProviderAccountCannotEnterTheInbox(): void
    {
        $body = str_replace('"data":', '"account":123,"data":', $this->body());
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        try {
            $this->receiver()->receive($body, WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp()), $now);
            self::fail('Malformed original account was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM provider_event_inbox'));
        }
    }

    public function testExpiredWorkerLeaseCanBeReclaimedAtItsDeadline(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $body = $this->body();
        $this->receiver()->receive($body, WebhookSignature::generateSignatureHeader($body, 'whsec_controlled', $now->getTimestamp()), $now);
        $inbox = new \App\Infrastructure\Finance\DbalProviderEventInbox($this->database);
        self::assertCount(1, $inbox->claimDue($now, 'crashed-worker', 1));
        self::assertSame([], $inbox->claimDue($now->modify('+59 seconds'), 'replacement-worker', 1));
        self::assertCount(1, $inbox->claimDue($now->modify('+60 seconds'), 'replacement-worker', 1));
    }

    public function testCompletingOneClaimDoesNotCompleteTheSameReferenceInAnotherScope(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $inbox = new \App\Infrastructure\Finance\DbalProviderEventInbox($this->database);
        foreach (['test:platform', 'test:other-platform'] as $scope) {
            $inbox->persist(new \App\Application\Finance\AuthenticatedProviderEvent($scope, 'evt_shared', '{"type":"account.updated"}'), $now);
        }
        $events = $inbox->claimDue($now, 'worker', 2);
        self::assertCount(2, $events);
        self::assertTrue($inbox->complete($events[0], 'worker', $now));
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM provider_event_inbox WHERE processing_state='completed'"));
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM provider_event_inbox WHERE processing_state='processing'"));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('claimOutcomes')]
    public function testAReusedWorkerNameCannotAcknowledgeAnOlderLease(bool $retry): void
    {
        $now = new \DateTimeImmutable('2026-10-10T11:00:00Z');
        $inbox = new \App\Infrastructure\Finance\DbalProviderEventInbox($this->database);
        $inbox->persist(new \App\Application\Finance\AuthenticatedProviderEvent('test:platform', 'evt_reclaimed', '{"type":"account.updated"}'), $now);
        $old = $inbox->claimDue($now, 'worker', 1)[0];
        $later = $now->modify('+60 seconds');
        $current = $inbox->claimDue($later, 'worker', 1)[0];
        self::assertFalse($retry ? $inbox->retry($old, 'worker', $later, $later->modify('+60 seconds')) : $inbox->complete($old, 'worker', $later));
        self::assertSame('processing', $this->database->fetchOne('SELECT processing_state FROM provider_event_inbox'));
        self::assertTrue($retry ? $inbox->retry($current, 'worker', $later, $later->modify('+60 seconds')) : $inbox->complete($current, 'worker', $later));
        self::assertSame($retry ? 'failed' : 'completed', $this->database->fetchOne('SELECT processing_state FROM provider_event_inbox'));
    }

    /** @return iterable<array{bool}> */
    public static function claimOutcomes(): iterable
    {
        yield 'complete' => [false];
        yield 'retry' => [true];
    }

    private function receiver(): \App\Application\Finance\ReceiveProviderEvent
    {
        return new \App\Application\Finance\ReceiveProviderEvent(new \App\Infrastructure\Finance\StripeProviderEventAuthenticator('whsec_controlled', 'test:platform', false), new \App\Infrastructure\Finance\DbalProviderEventInbox($this->database));
    }

    private function body(): string
    {
        return '{"id":"evt_original","object":"event","type":"checkout.session.completed","api_version":"2026-09-30.endive","created":1791630000,"livemode":false,"data":{"object":{"id":"cs_original","object":"checkout.session","status":"complete","payment_status":"paid","amount_total":10500,"currency":"eur","client_reference_id":"checkout-original","payment_intent":"pi_original","metadata":{"checkout_id":"checkout-original","operation_id":"operation-original"},"customer":"cus_private","customer_details":{"email":"private@example.test"}}}}';
    }
}
