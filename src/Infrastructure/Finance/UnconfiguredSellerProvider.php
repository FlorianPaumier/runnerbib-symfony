<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\SellerProvider;
use App\Application\Finance\SellerProviderUnavailable;
use App\Domain\Finance\SellerIdentity;
use App\Domain\Finance\SellerReadiness;

final readonly class UnconfiguredSellerProvider implements SellerProvider
{
    public function configurationFingerprint(): string
    {
        throw new SellerProviderUnavailable('Seller provider operator configuration is required.');
    }

    public function assertConfigured(): void
    {
        throw new SellerProviderUnavailable('Seller provider operator configuration is required.');
    }

    public function createRecipient(SellerIdentity $identity, string $operationId, string $idempotencyKey): string
    {
        throw new SellerProviderUnavailable('Seller provider operator configuration is required.');
    }

    public function readiness(string $providerReference): SellerReadiness
    {
        throw new SellerProviderUnavailable('Seller provider operator configuration is required.');
    }

    public function onboardingLink(string $providerReference): string
    {
        throw new SellerProviderUnavailable('Seller provider operator configuration is required.');
    }
}
