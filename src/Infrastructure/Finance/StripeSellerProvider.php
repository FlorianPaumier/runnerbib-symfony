<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\SellerProvider;
use App\Application\Finance\SellerProviderUnavailable;
use App\Domain\Finance\SellerIdentity;
use App\Domain\Finance\SellerReadiness;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final readonly class StripeSellerProvider implements SellerProvider
{
    public function __construct(private StripeClient $client, private StripeSellerConfiguration $configuration)
    {
    }

    public function configurationFingerprint(): string
    {
        return hash('sha256', json_encode(['api_version' => \Stripe\Util\ApiVersion::CURRENT, 'dashboard' => $this->configuration->dashboard, 'fees_collector' => $this->configuration->feesCollector, 'losses_collector' => $this->configuration->lossesCollector, 'entity_type' => 'individual', 'capability' => 'recipient.stripe_balance.stripe_transfers', 'bank_payout_interval' => 'daily'], JSON_THROW_ON_ERROR));
    }

    public function assertConfigured(): void
    {
    }

    public function createRecipient(SellerIdentity $identity, string $operationId, string $idempotencyKey): string
    {
        try {
            $account = $this->client->v2->core->accounts->create([
                'configuration' => ['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]],
                'identity' => ['country' => $identity->country, 'entity_type' => 'individual'],
                'contact_email' => $identity->email,
                'dashboard' => $this->configuration->dashboard,
                'defaults' => ['responsibilities' => ['fees_collector' => $this->configuration->feesCollector, 'losses_collector' => $this->configuration->lossesCollector]],
                'metadata' => ['seller_onboarding_operation' => $operationId],
            ], ['idempotency_key' => $idempotencyKey]);
            $reference = $account->toArray()['id'] ?? null;
            if (!is_string($reference) || '' === $reference) {
                throw new SellerProviderUnavailable('Stripe did not return a recipient reference.');
            }
            $this->client->balanceSettings->update(['payments' => ['payouts' => ['schedule' => ['interval' => 'daily']]]], ['stripe_account' => $reference, 'idempotency_key' => 'seller-bank-payouts:'.$operationId]);

            return $reference;
        } catch (ApiErrorException $exception) {
            throw new SellerProviderUnavailable('Stripe recipient creation requires reconciliation.', previous: $exception);
        }
    }

    public function readiness(string $providerReference): SellerReadiness
    {
        try {
            $account = $this->client->v2->core->accounts->retrieve($providerReference, ['include' => ['configuration.recipient', 'requirements']])->toArray();
        } catch (ApiErrorException $exception) {
            throw new SellerProviderUnavailable('Stripe seller readiness is unavailable.', previous: $exception);
        }
        if (($account['id'] ?? null) !== $providerReference || true === ($account['closed'] ?? null)) {
            return SellerReadiness::Restricted;
        }
        $status = $this->value($account, ['configuration', 'recipient', 'capabilities', 'stripe_balance', 'stripe_transfers', 'status']);

        $entries = $this->value($account, ['requirements', 'entries']);

        if (!is_array($entries)) {
            return SellerReadiness::Restricted;
        }

        return SellerReadiness::fromRecipient(is_string($status) ? $status : 'unknown', [] !== $entries);
    }

    public function onboardingLink(string $providerReference): string
    {
        try {
            $link = $this->client->v2->core->accountLinks->create(['account' => $providerReference, 'use_case' => ['type' => 'account_onboarding', 'account_onboarding' => ['collection_options' => ['fields' => 'eventually_due', 'future_requirements' => 'include'], 'return_url' => $this->configuration->returnUrl, 'refresh_url' => $this->configuration->refreshUrl]]])->toArray();
        } catch (ApiErrorException $exception) {
            throw new SellerProviderUnavailable('Stripe hosted onboarding is unavailable.', previous: $exception);
        }
        $url = $link['url'] ?? null;
        if (!is_string($url) || false === filter_var($url, FILTER_VALIDATE_URL) || 'https' !== parse_url($url, PHP_URL_SCHEME)) {
            throw new SellerProviderUnavailable('Stripe returned an invalid hosted onboarding link.');
        }

        return $url;
    }

    /** @param array<array-key, mixed> $data
     * @param list<string> $path
     */
    private function value(array $data, array $path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
