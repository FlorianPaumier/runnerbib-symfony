<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Application\Finance\HostedCheckoutIntent;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

final class StripeHostedCheckoutProviderTest extends TestCase
{
    public function testExpiredSessionWithProcessingOriginalIntentRetainsProtection(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account = ['id' => 'cs_original', 'object' => 'checkout.session', 'amount_total' => 10500, 'currency' => 'eur', 'client_reference_id' => 'checkout-one', 'metadata' => ['checkout_id' => 'checkout-one', 'operation_id' => 'operation-one'], 'status' => 'expired', 'payment_status' => 'unpaid', 'payment_intent' => ['id' => 'pi_original', 'object' => 'payment_intent', 'status' => 'processing', 'amount' => 10500, 'currency' => 'eur', 'metadata' => ['checkout_id' => 'checkout-one', 'operation_id' => 'operation-one']]];
        ApiRequestor::setHttpClient($http);
        $claim = new \App\Application\Finance\CheckoutReconciliation('checkout-one', 'operation-one', 10500, 'EUR', 'cs_original', 1, new \DateTimeImmutable('2026-10-10T11:15:00Z'), '2026-09-30.endive', true);
        $state = (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->inspect($claim);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Processing, $state);
        self::assertSame('https://api.stripe.com/v1/checkout/sessions/cs_original', $http->requests[0]['url']);
        self::assertSame(['expand' => ['payment_intent']], $http->requests[0]['params']);
        self::assertIsArray($http->requests[0]['headers']);
        self::assertContains('Stripe-Version: 2026-09-30.endive', $http->requests[0]['headers']);
    }

    /** @param array<string,mixed> $facts */
    #[\PHPUnit\Framework\Attributes\DataProvider('lifecycleFacts')]
    public function testOriginalPaymentFactsClassifyOnlySafeClosure(array $facts, \App\Domain\Finance\HostedPaymentState $expected): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account = array_replace(['id' => 'cs_original', 'object' => 'checkout.session', 'amount_total' => 10500, 'currency' => 'eur', 'client_reference_id' => 'checkout-one', 'metadata' => ['checkout_id' => 'checkout-one', 'operation_id' => 'operation-one'], 'status' => 'expired', 'payment_status' => 'unpaid', 'payment_intent' => null], $facts);
        ApiRequestor::setHttpClient($http);
        $claim = new \App\Application\Finance\CheckoutReconciliation('checkout-one', 'operation-one', 10500, 'EUR', 'cs_original', 1, new \DateTimeImmutable('2026-10-10T11:15:00Z'), '2026-09-30.endive', true);
        self::assertSame($expected, (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->inspect($claim));
    }

    /** @return iterable<string,array{array<string,mixed>,\App\Domain\Finance\HostedPaymentState}> */
    public static function lifecycleFacts(): iterable
    {
        $intent = ['id' => 'pi_original', 'object' => 'payment_intent', 'status' => 'canceled', 'amount' => 10500, 'currency' => 'eur', 'metadata' => ['checkout_id' => 'checkout-one', 'operation_id' => 'operation-one']];
        yield 'expired without intent' => [[], \App\Domain\Finance\HostedPaymentState::Closed];
        yield 'expired cancelled intent' => [['payment_intent' => $intent], \App\Domain\Finance\HostedPaymentState::Closed];
        yield 'expired unpaid intent awaiting card' => [['payment_intent' => array_replace($intent, ['status' => 'requires_payment_method'])], \App\Domain\Finance\HostedPaymentState::Unknown];
        yield 'paid completed intent' => [['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => array_replace($intent, ['status' => 'succeeded'])], \App\Domain\Finance\HostedPaymentState::Paid];
        yield 'open untouched session' => [['status' => 'open'], \App\Domain\Finance\HostedPaymentState::Open];
        yield 'paid without expanded intent' => [['status' => 'complete', 'payment_status' => 'paid'], \App\Domain\Finance\HostedPaymentState::Unknown];
        yield 'unexpanded intent' => [['payment_intent' => 'pi_original'], \App\Domain\Finance\HostedPaymentState::Unknown];
        yield 'wrong intent amount' => [['payment_intent' => array_replace($intent, ['amount' => 10499])], \App\Domain\Finance\HostedPaymentState::Unknown];
        yield 'wrong intent operation' => [['payment_intent' => array_replace($intent, ['metadata' => ['checkout_id' => 'checkout-one', 'operation_id' => 'other']])], \App\Domain\Finance\HostedPaymentState::Unknown];
        yield 'contradictory paid cancellation' => [['payment_status' => 'paid', 'payment_intent' => $intent], \App\Domain\Finance\HostedPaymentState::Unknown];
        yield 'wrong original session' => [['id' => 'cs_other'], \App\Domain\Finance\HostedPaymentState::Unknown];
    }

    public function testOriginalSessionExpirationUsesStableKeyAndCommittedVersion(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account = ['id' => 'cs_original', 'object' => 'checkout.session', 'status' => 'expired'];
        ApiRequestor::setHttpClient($http);
        $claim = new \App\Application\Finance\CheckoutReconciliation('checkout-one', 'operation-one', 10500, 'EUR', 'cs_original', 1, new \DateTimeImmutable('2026-10-10T11:15:00Z'), '2026-09-30.endive', true);
        (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->expire($claim, 'checkout-expire:checkout-one');
        self::assertSame('https://api.stripe.com/v1/checkout/sessions/cs_original/expire', $http->requests[0]['url']);
        self::assertSame('post', $http->requests[0]['method']);
        self::assertIsArray($http->requests[0]['headers']);
        self::assertContains('Idempotency-Key: checkout-expire:checkout-one', $http->requests[0]['headers']);
        self::assertContains('Stripe-Version: 2026-09-30.endive', $http->requests[0]['headers']);
    }

    public function testLifecycleProviderOutageIsUnknownAndNeverClaimsFailure(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->failRequests = true;
        ApiRequestor::setHttpClient($http);
        $claim = new \App\Application\Finance\CheckoutReconciliation('checkout-one', 'operation-one', 10500, 'EUR', 'cs_original', 1, new \DateTimeImmutable('2026-10-10T11:15:00Z'), '2026-09-30.endive', true);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Unknown, (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->inspect($claim));
    }

    public function testExpirationOutageCrossesThePortAsUncertainRuntimeFailure(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->failRequests = true;
        ApiRequestor::setHttpClient($http);
        $claim = new \App\Application\Finance\CheckoutReconciliation('checkout-one', 'operation-one', 10500, 'EUR', 'cs_original', 1, new \DateTimeImmutable('2026-10-10T11:15:00Z'), '2026-09-30.endive', true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Original Session expiration requires reconciliation.');
        (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->expire($claim, 'checkout-expire:checkout-one');
    }

    public function testOriginalSessionWithoutIntentFieldIsUncertainRatherThanSafelyClosed(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account = ['id' => 'cs_original', 'object' => 'checkout.session', 'amount_total' => 10500, 'currency' => 'eur', 'client_reference_id' => 'checkout-one', 'metadata' => ['checkout_id' => 'checkout-one', 'operation_id' => 'operation-one'], 'status' => 'expired', 'payment_status' => 'unpaid'];
        ApiRequestor::setHttpClient($http);
        $claim = new \App\Application\Finance\CheckoutReconciliation('checkout-one', 'operation-one', 10500, 'EUR', 'cs_original', 1, new \DateTimeImmutable('2026-10-10T11:15:00Z'), '2026-09-30.endive', true);
        self::assertSame(\App\Domain\Finance\HostedPaymentState::Unknown, (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->inspect($claim));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());
        parent::tearDown();
    }

    public function testHostedPlatformPaymentUsesCommittedMoneyAndOriginalCorrelation(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account = ['id' => 'cs_controlled', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_controlled', 'client_reference_id' => 'checkout-one', 'amount_total' => 10500, 'currency' => 'eur', 'payment_status' => 'unpaid', 'status' => 'open'];
        ApiRequestor::setHttpClient($http);
        $provider = new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled'));
        $intent = new HostedCheckoutIntent('operation-one', 'checkout-one', 10500, 'EUR', (object) ['provider_configuration' => (object) ['success_url' => 'https://runnerbib.test/success', 'cancel_url' => 'https://runnerbib.test/cancel', 'api_version' => '2026-09-30.endive', 'automatic_tax' => false]], 1);
        $session = $provider->create($intent, 'checkout-payment:operation-one');
        self::assertSame('cs_controlled', $session->sessionReference);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_controlled', $session->url);
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', $http->requests[0]['url']);
        $parameters = $http->requests[0]['params'];
        self::assertIsArray($parameters);
        self::assertSame('payment', $parameters['mode']);
        self::assertSame(['card'], $parameters['payment_method_types']);
        self::assertSame(['enabled' => 'false'], $parameters['automatic_tax']);
        self::assertSame('checkout-one', $parameters['client_reference_id']);
        self::assertSame(['checkout_id' => 'checkout-one', 'operation_id' => 'operation-one'], $parameters['metadata']);
        self::assertSame([['price_data' => ['currency' => 'eur', 'unit_amount' => 10500, 'product_data' => ['name' => 'Commande de dossards']], 'quantity' => 1]], $parameters['line_items']);
        self::assertArrayNotHasKey('customer_email', $parameters);
        self::assertArrayNotHasKey('expires_at', $parameters);
        self::assertIsArray($http->requests[0]['headers']);
        self::assertContains('Idempotency-Key: checkout-payment:operation-one', $http->requests[0]['headers']);
        self::assertContains('Stripe-Version: 2026-09-30.endive', $http->requests[0]['headers']);
    }

    public function testOriginalSessionRetrievalDoesNotCreatePaymentAndAcceptsClosedUrl(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account = ['id' => 'cs_controlled', 'object' => 'checkout.session', 'url' => null];
        ApiRequestor::setHttpClient($http);
        $session = (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->retrieve('cs_controlled');
        self::assertSame('cs_controlled', $session->sessionReference);
        self::assertNull($session->url);
        self::assertSame('https://api.stripe.com/v1/checkout/sessions/cs_controlled', $http->requests[0]['url']);
    }

    public function testProviderOutageIsSanitizedAndRequiresReconciliation(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->failRequests = true;
        ApiRequestor::setHttpClient($http);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hosted payment retrieval is unavailable.');
        (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->retrieve('cs_controlled');
    }

    /** @param array<string,mixed> $response */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeResponses')]
    public function testUnsafeOrUnrelatedProviderResponsesCannotSupplyAPaymentLink(array $response): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account = $response;
        ApiRequestor::setHttpClient($http);
        $this->expectException(\RuntimeException::class);
        (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->retrieve('cs_controlled');
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function unsafeResponses(): iterable
    {
        yield 'another session' => [['id' => 'cs_other', 'object' => 'checkout.session', 'url' => null]];
        yield 'bad url' => [['id' => 'cs_controlled', 'object' => 'checkout.session', 'url' => 'javascript:alert(1)']];
        yield 'lookalike host' => [['id' => 'cs_controlled', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com.evil.test/pay']];
        yield 'unsecure url' => [['id' => 'cs_controlled', 'object' => 'checkout.session', 'url' => 'http://checkout.stripe.com/pay']];
        yield 'url type' => [['id' => 'cs_controlled', 'object' => 'checkout.session', 'url' => 12]];
    }

    public function testMissingCommittedConfigurationCannotSendAProviderRequest(): void
    {
        $http = new ControlledStripeHttpClient();
        ApiRequestor::setHttpClient($http);
        try {
            (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->create(new HostedCheckoutIntent('operation', 'checkout', 10500, 'EUR', new \stdClass(), 1), 'original-key');
            self::fail('Missing immutable provider configuration accepted.');
        } catch (\DomainException) {
            self::assertSame([], $http->requests);
        }
    }

    public function testHostedCreationOutageIsAnUnknownOutcomeAndNotASuccess(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->failRequests = true;
        ApiRequestor::setHttpClient($http);
        $intent = new HostedCheckoutIntent('operation-one', 'checkout-one', 10500, 'EUR', (object) ['provider_configuration' => (object) ['success_url' => 'https://runnerbib.test/success', 'cancel_url' => 'https://runnerbib.test/cancel', 'api_version' => '2026-09-30.endive', 'automatic_tax' => false]], 1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hosted payment creation requires reconciliation.');
        (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->create($intent, 'original-key');
    }

    public function testReturnedProviderAmountMustMatchCommittedBuyerTotal(): void
    {
        $http = new ControlledStripeHttpClient();
        $http->account = ['id' => 'cs_controlled', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_controlled', 'client_reference_id' => 'checkout-one', 'amount_total' => 10000, 'currency' => 'eur'];
        ApiRequestor::setHttpClient($http);
        $intent = new HostedCheckoutIntent('operation-one', 'checkout-one', 10500, 'EUR', (object) ['provider_configuration' => (object) ['success_url' => 'https://runnerbib.test/success', 'cancel_url' => 'https://runnerbib.test/cancel', 'api_version' => '2026-09-30.endive', 'automatic_tax' => false]], 1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hosted payment did not match committed terms.');
        (new \App\Infrastructure\Finance\StripeHostedCheckoutProvider(new StripeClient('sk_test_controlled')))->create($intent, 'original-key');
    }
}
