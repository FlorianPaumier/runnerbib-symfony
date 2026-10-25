<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\HostedCheckoutProvider;
use Stripe\StripeClient;

final readonly class HostedCheckoutProviderFactory
{
    public function create(string $apiKey, string $providerScope): HostedCheckoutProvider
    {
        if ('' === trim($apiKey) || '' === trim($providerScope) || 'unconfigured' === $providerScope) {
            return new UnconfiguredHostedCheckoutProvider();
        }

        return new StripeHostedCheckoutProvider(new StripeClient($apiKey));
    }
}
