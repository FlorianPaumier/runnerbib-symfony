<?php

declare(strict_types=1);

namespace App\Application\Finance;

interface HostedCheckoutProvider
{
    public function inspect(CheckoutReconciliation $claim): \App\Domain\Finance\HostedPaymentState;

    public function expire(CheckoutReconciliation $claim, string $idempotencyKey): void;

    public function assertConfigured(): void;

    public function retrieve(string $sessionReference): HostedCheckoutSession;

    public function create(HostedCheckoutIntent $intent, string $idempotencyKey): HostedCheckoutSession;
}
