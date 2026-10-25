<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\HostedCheckoutIntent;
use App\Application\Finance\HostedCheckoutProvider;
use App\Application\Finance\HostedCheckoutSession;
use App\Application\Finance\SellerProviderUnavailable;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final readonly class StripeHostedCheckoutProvider implements HostedCheckoutProvider
{
    public function __construct(private StripeClient $client)
    {
    }

    public function inspect(\App\Application\Finance\CheckoutReconciliation $claim): \App\Domain\Finance\HostedPaymentState
    {
        try {
            $session = $this->client->checkout->sessions->retrieve($claim->sessionReference ?? '', ['expand' => ['payment_intent']], ['stripe_version' => $claim->apiVersion])->toArray();
        } catch (ApiErrorException) {
            return \App\Domain\Finance\HostedPaymentState::Unknown;
        }
        if (($session['id'] ?? null) !== $claim->sessionReference || ($session['amount_total'] ?? null) !== $claim->totalCents || ($session['currency'] ?? null) !== strtolower($claim->currency) || ($session['client_reference_id'] ?? null) !== $claim->checkoutId || !is_array($session['metadata'] ?? null) || ($session['metadata']['checkout_id'] ?? null) !== $claim->checkoutId || ($session['metadata']['operation_id'] ?? null) !== $claim->operationId) {
            return \App\Domain\Finance\HostedPaymentState::Unknown;
        }
        if (!array_key_exists('payment_intent', $session)) {
            return \App\Domain\Finance\HostedPaymentState::Unknown;
        }
        $intent = $session['payment_intent'];
        if (null !== $intent && (!is_array($intent) || !is_string($intent['id'] ?? null) || '' === $intent['id'] || ($intent['amount'] ?? null) !== $claim->totalCents || ($intent['currency'] ?? null) !== strtolower($claim->currency) || !is_array($intent['metadata'] ?? null) || ($intent['metadata']['checkout_id'] ?? null) !== $claim->checkoutId || ($intent['metadata']['operation_id'] ?? null) !== $claim->operationId)) {
            return \App\Domain\Finance\HostedPaymentState::Unknown;
        }
        $intentStatus = is_array($intent) ? ($intent['status'] ?? null) : null;
        $sessionStatus = $session['status'] ?? null;
        $paymentStatus = $session['payment_status'] ?? null;
        if ('processing' === $intentStatus) {
            return \App\Domain\Finance\HostedPaymentState::Processing;
        }
        if ('complete' === $sessionStatus && 'paid' === $paymentStatus && 'succeeded' === $intentStatus) {
            return \App\Domain\Finance\HostedPaymentState::Paid;
        }
        if ('unpaid' !== $paymentStatus) {
            return \App\Domain\Finance\HostedPaymentState::Unknown;
        }
        if ('expired' === $sessionStatus && (null === $intent || 'canceled' === $intentStatus)) {
            return \App\Domain\Finance\HostedPaymentState::Closed;
        }
        if ('open' === $sessionStatus && (null === $intent || 'requires_payment_method' === $intentStatus)) {
            return \App\Domain\Finance\HostedPaymentState::Open;
        }

        return \App\Domain\Finance\HostedPaymentState::Unknown;
    }

    public function expire(\App\Application\Finance\CheckoutReconciliation $claim, string $idempotencyKey): void
    {
        try {
            $this->client->checkout->sessions->expire($claim->sessionReference ?? '', [], ['stripe_version' => $claim->apiVersion, 'idempotency_key' => $idempotencyKey]);
        } catch (ApiErrorException $exception) {
            throw new SellerProviderUnavailable('Original Session expiration requires reconciliation.', previous: $exception);
        }
    }

    public function assertConfigured(): void
    {
    }

    public function create(HostedCheckoutIntent $intent, string $idempotencyKey): HostedCheckoutSession
    {
        $configuration = $intent->parameters->provider_configuration ?? null;
        if (!$configuration instanceof \stdClass || !is_string($configuration->success_url ?? null) || !is_string($configuration->cancel_url ?? null) || !is_string($configuration->api_version ?? null) || false !== ($configuration->automatic_tax ?? null) || 'EUR' !== $intent->currency || $intent->totalCents <= 0) {
            throw new \DomainException('Committed Checkout provider configuration is unavailable.');
        }
        try {
            $session = $this->client->checkout->sessions->create([
                'mode' => 'payment', 'payment_method_types' => ['card'], 'locale' => 'fr', 'automatic_tax' => ['enabled' => false],
                'success_url' => $configuration->success_url, 'cancel_url' => $configuration->cancel_url,
                'client_reference_id' => $intent->checkoutId, 'metadata' => ['checkout_id' => $intent->checkoutId, 'operation_id' => $intent->operationId],
                'payment_intent_data' => ['metadata' => ['checkout_id' => $intent->checkoutId, 'operation_id' => $intent->operationId]],
                'line_items' => [['price_data' => ['currency' => 'eur', 'unit_amount' => $intent->totalCents, 'product_data' => ['name' => 'Commande de dossards']], 'quantity' => 1]],
            ], ['idempotency_key' => $idempotencyKey, 'stripe_version' => $configuration->api_version])->toArray();
        } catch (ApiErrorException $exception) {
            throw new SellerProviderUnavailable('Hosted payment creation requires reconciliation.', previous: $exception);
        }
        if (($session['amount_total'] ?? null) !== $intent->totalCents || ($session['currency'] ?? null) !== 'eur' || ($session['client_reference_id'] ?? null) !== $intent->checkoutId) {
            throw new SellerProviderUnavailable('Hosted payment did not match committed terms.');
        }

        return $this->session($session);
    }

    public function retrieve(string $sessionReference): HostedCheckoutSession
    {
        try {
            $session = $this->client->checkout->sessions->retrieve($sessionReference)->toArray();
        } catch (ApiErrorException $exception) {
            throw new SellerProviderUnavailable('Hosted payment retrieval is unavailable.', previous: $exception);
        }
        if (($session['id'] ?? null) !== $sessionReference) {
            throw new SellerProviderUnavailable('Hosted payment identity did not match.');
        }

        return $this->session($session);
    }

    /** @param array<array-key,mixed> $session */
    private function session(array $session): HostedCheckoutSession
    {
        $reference = $session['id'] ?? null;
        $url = $session['url'] ?? null;
        if (!is_string($reference) || '' === $reference || (null !== $url && (!is_string($url) || false === filter_var($url, FILTER_VALIDATE_URL) || 'https' !== parse_url($url, PHP_URL_SCHEME) || 'checkout.stripe.com' !== parse_url($url, PHP_URL_HOST)))) {
            throw new SellerProviderUnavailable('Hosted payment response is incomplete.');
        }

        return new HostedCheckoutSession($reference, $url);
    }
}
