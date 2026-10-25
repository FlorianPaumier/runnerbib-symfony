<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

final readonly class StripeSellerConfiguration
{
    public function __construct(public string $apiKey, public string $dashboard, public string $feesCollector, public string $lossesCollector, public string $returnUrl, public string $refreshUrl)
    {
        if ('' === trim($apiKey)) {
            throw new \InvalidArgumentException('Stripe API key is required.');
        }
        foreach ([$returnUrl, $refreshUrl] as $url) {
            if (false === filter_var($url, FILTER_VALIDATE_URL) || 'https' !== parse_url($url, PHP_URL_SCHEME)) {
                throw new \InvalidArgumentException('Hosted onboarding callbacks require secure URLs.');
            }
        }
        if (!in_array($dashboard, ['express', 'full', 'none'], true) || !in_array($feesCollector, ['application', 'stripe'], true) || !in_array($lossesCollector, ['application', 'stripe'], true)) {
            throw new \InvalidArgumentException('Operator dashboard and responsibility configuration is required.');
        }
    }
}
