<?php

declare(strict_types=1);

namespace App\Application\Finance;

final readonly class SellerOnboardingView
{
    public function __construct(public string $providerReference, public string $readiness, public ?string $onboardingUrl)
    {
    }
}
