<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Support\IsolatedWebTestCase;
use Stripe\WebhookSignature;

final class ProviderWebhookHttpTest extends IsolatedWebTestCase
{
    public function testUnconfiguredWebhookRefusesWithoutStoringOrLeakingPayload(): void
    {
        $client = self::createClient();
        $client->request('POST', '/webhooks/stripe', content: '{"email":"private@example.test"}');
        self::assertResponseStatusCodeSame(503);
        self::assertSame('Service temporairement indisponible.', $client->getResponse()->getContent());
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM provider_event_inbox'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM purchases'));
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
    }

    public function testSignedRawRequestIsPersistedOnceWithoutAuthorizingAPurchase(): void
    {
        $server = $_SERVER;
        $environment = $_ENV;
        $_SERVER['STRIPE_WEBHOOK_SECRET'] = $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test_only_http';
        $_SERVER['STRIPE_PROVIDER_SCOPE'] = $_ENV['STRIPE_PROVIDER_SCOPE'] = 'test:http';
        $_SERVER['STRIPE_LIVE_MODE'] = $_ENV['STRIPE_LIVE_MODE'] = '0';
        try {
            $body = '{"id":"evt_http","type":"checkout.session.completed","api_version":"2026-09-30.endive","created":1791630000,"livemode":false,"data":{"object":{"id":"cs_http","object":"checkout.session","amount_total":10500,"currency":"eur","customer_details":{"email":"private@example.test"}}}}';
            $signature = WebhookSignature::generateSignatureHeader($body, 'whsec_test_only_http', time());
            $client = self::createClient();
            $client->request('POST', '/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $body);
            self::assertResponseStatusCodeSame(204);
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM provider_event_inbox'));
            self::assertSame('pending', $this->database->fetchOne('SELECT processing_state FROM provider_event_inbox'));
            $payload = $this->database->fetchOne('SELECT protected_payload FROM provider_event_inbox');
            self::assertIsString($payload);
            self::assertStringNotContainsString('private@example.test', $payload);
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM purchases'));
            $client->request('POST', '/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => $signature], content: $body);
            self::assertResponseStatusCodeSame(204);
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM provider_event_inbox'));
            $client->request('POST', '/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => $signature], content: str_replace('10500', '1', $body));
            self::assertResponseStatusCodeSame(400);
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM provider_event_inbox'));
            $this->database->executeStatement('ALTER TABLE provider_event_inbox ADD CONSTRAINT reject_http_event CHECK(false) NOT VALID');
            $retryBody = str_replace('evt_http', 'evt_retry', $body);
            $retrySignature = WebhookSignature::generateSignatureHeader($retryBody, 'whsec_test_only_http', time());
            $client->request('POST', '/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => $retrySignature], content: $retryBody);
            self::assertResponseStatusCodeSame(503);
            self::assertSame('Service temporairement indisponible.', $client->getResponse()->getContent());
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM provider_event_inbox'));
            $this->database->executeStatement('ALTER TABLE provider_event_inbox DROP CONSTRAINT reject_http_event');
            $client->request('POST', '/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => $retrySignature], content: $retryBody);
            self::assertResponseStatusCodeSame(204);
            self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM provider_event_inbox'));
            $wrongMode = str_replace('"livemode":false', '"livemode":true', $body);
            $client->request('POST', '/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($wrongMode, 'whsec_test_only_http', time())], content: $wrongMode);
            self::assertResponseStatusCodeSame(400);
            self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM provider_event_inbox'));
        } finally {
            self::ensureKernelShutdown();
            $_SERVER = $server;
            $_ENV = $environment;
        }
    }
}
