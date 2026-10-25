<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use PHPUnit\Framework\TestCase;

final class HostedCheckoutProviderFactoryTest extends TestCase
{
    public function testMissingOperatorConfigurationProvidesExplicitFailClosedAdapter(): void
    {
        $provider = (new \App\Infrastructure\Finance\HostedCheckoutProviderFactory())->create('', 'unconfigured');
        $this->expectException(\RuntimeException::class);
        $provider->assertConfigured();
    }

    public function testExplicitOperatorConfigurationBuildsTheSdkAdapterWithoutCallingStripe(): void
    {
        $provider = (new \App\Infrastructure\Finance\HostedCheckoutProviderFactory())->create('sk_test_controlled', 'test:platform');
        $provider->assertConfigured();
        self::assertInstanceOf(\App\Infrastructure\Finance\StripeHostedCheckoutProvider::class, $provider);
    }

    public function testUnconfiguredAdapterCannotCreateOrRetrieveProviderPayments(): void
    {
        $provider = (new \App\Infrastructure\Finance\HostedCheckoutProviderFactory())->create('', 'unconfigured');
        try {
            $provider->create(new \App\Application\Finance\HostedCheckoutIntent('operation', 'checkout', 10500, 'EUR', new \stdClass(), 1), 'original-key');
            self::fail('Unconfigured provider created a payment.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Hosted payment operator configuration is required.', $exception->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        $provider->retrieve('cs_known');
    }

    public function testUnconfiguredProviderCannotInspectOrExpireTheOriginalPath(): void
    {
        $provider = (new \App\Infrastructure\Finance\HostedCheckoutProviderFactory())->create('', 'unconfigured');
        $claim = new \App\Application\Finance\CheckoutReconciliation('checkout', 'operation', 10500, 'EUR', 'cs_original', 1, new \DateTimeImmutable('2026-10-10T11:15:00Z'), '2026-09-30.endive', true);
        try {
            $provider->inspect($claim);
            self::fail('Unconfigured payment path was inspected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Hosted payment operator configuration is required.', $exception->getMessage());
        }
        try {
            $provider->expire($claim, 'original-expiry-key');
            self::fail('Unconfigured payment path was mutated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Hosted payment operator configuration is required.', $exception->getMessage());
        }
    }
}
