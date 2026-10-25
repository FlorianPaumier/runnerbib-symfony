<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\SellerProvider;
use Stripe\StripeClient;

final readonly class SellerProviderFactory
{
    public function create(string $apiKey, string $dashboard, string $feesCollector, string $lossesCollector, string $returnUrl, string $refreshUrl, string $providerScope): SellerProvider
    {
        if ('' === trim($providerScope) || 'unconfigured' === $providerScope) {
            return new UnconfiguredSellerProvider();
        }
        try {
            $configuration = new StripeSellerConfiguration($apiKey, $dashboard, $feesCollector, $lossesCollector, $returnUrl, $refreshUrl);
        } catch (\InvalidArgumentException) {
            return new UnconfiguredSellerProvider();
        }

        return new StripeSellerProvider(new StripeClient($apiKey), $configuration);
    }
}
