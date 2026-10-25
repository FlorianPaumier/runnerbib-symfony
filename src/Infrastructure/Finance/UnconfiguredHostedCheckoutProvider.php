<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\HostedCheckoutIntent;
use App\Application\Finance\HostedCheckoutProvider;
use App\Application\Finance\HostedCheckoutSession;

final readonly class UnconfiguredHostedCheckoutProvider implements HostedCheckoutProvider
{
    public function inspect(\App\Application\Finance\CheckoutReconciliation $claim): \App\Domain\Finance\HostedPaymentState
    {
        throw new \RuntimeException('Hosted payment operator configuration is required.');
    }

    public function expire(\App\Application\Finance\CheckoutReconciliation $claim, string $idempotencyKey): void
    {
        throw new \RuntimeException('Hosted payment operator configuration is required.');
    }

    public function assertConfigured(): void
    {
        throw new \RuntimeException('Hosted payment operator configuration is required.');
    }

    public function create(HostedCheckoutIntent $intent, string $idempotencyKey): HostedCheckoutSession
    {
        throw new \RuntimeException('Hosted payment operator configuration is required.');
    }

    public function retrieve(string $sessionReference): HostedCheckoutSession
    {
        throw new \RuntimeException('Hosted payment operator configuration is required.');
    }
}
