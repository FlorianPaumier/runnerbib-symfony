<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Infrastructure\Finance\StripeSellerConfiguration;
use PHPUnit\Framework\TestCase;

final class StripeSellerConfigurationTest extends TestCase
{
    public function testOperatorMustSupplyDashboardAndResponsibilityConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StripeSellerConfiguration('sk_test_local', '', '', '', 'https://runner.example/onboarding/return', 'https://runner.example/onboarding/refresh');
    }

    public function testMissingKeyDoesNotEnableProviderRequests(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StripeSellerConfiguration('', 'express', 'application', 'application', 'https://runner.example/onboarding/return', 'https://runner.example/onboarding/refresh');
    }

    public function testHostedReturnAndRefreshMustUseSecureOperatorUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StripeSellerConfiguration('sk_test_local', 'express', 'application', 'application', 'http://runner.example/return', 'https://runner.example/refresh');
    }
}
