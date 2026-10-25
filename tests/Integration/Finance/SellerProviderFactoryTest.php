<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Infrastructure\Finance\SellerProviderFactory;
use PHPUnit\Framework\TestCase;

final class SellerProviderFactoryTest extends TestCase
{
    public function testMissingOperatorConfigurationCreatesFailClosedProviderWithoutBootFailure(): void
    {
        $provider = (new SellerProviderFactory())->create('', '', '', '', '', '', 'unconfigured');
        $this->expectException(\App\Application\Finance\SellerProviderUnavailable::class);
        $provider->readiness('acct_existing');
    }

    public function testExplicitOperatorConfigurationSelectsRealSdkAdapterWithoutMakingRequests(): void
    {
        $provider = (new SellerProviderFactory())->create('sk_test_controlled', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh', 'test-platform');
        self::assertInstanceOf(\App\Infrastructure\Finance\StripeSellerProvider::class, $provider);
    }

    public function testInvalidExplicitConfigurationStillFailsClosed(): void
    {
        $provider = (new SellerProviderFactory())->create('', 'express', 'application', 'application', 'https://runner.example/return', 'https://runner.example/refresh', 'test:platform');
        $this->expectException(\App\Application\Finance\SellerProviderUnavailable::class);
        $provider->assertConfigured();
    }

    public function testEveryUnconfiguredSellerOperationRefusesBeforeProviderEffects(): void
    {
        $provider = (new SellerProviderFactory())->create('', '', '', '', '', '', 'unconfigured');
        foreach ([static fn () => $provider->configurationFingerprint(), static fn () => $provider->createRecipient(new \App\Domain\Finance\SellerIdentity('FR', 'seller@example.test'), 'operation', 'original-key'), static fn () => $provider->onboardingLink('acct_original')] as $operation) {
            try {
                $operation();
                self::fail('Unconfigured seller operation succeeded.');
            } catch (\App\Application\Finance\SellerProviderUnavailable $exception) {
                self::assertSame('Seller provider operator configuration is required.', $exception->getMessage());
            }
        }
    }
}
