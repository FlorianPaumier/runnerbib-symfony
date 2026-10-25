<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Domain\Finance\HostedPaymentState;

final readonly class ReconcileCheckout
{
    public function __construct(private CheckoutReconciliationStore $store, private HostedCheckoutProvider $provider)
    {
    }

    /**
     * @return array{reconciled:int,failed:int}
     */
    public function reconcileDue(\DateTimeImmutable $now, string $worker, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000.');
        }
        $processed = 0;
        $failed = 0;
        foreach ($this->store->due($now, $limit) as $checkoutId) {
            try {
                $this->reconcile($checkoutId, $now, $worker);
            } catch (\RuntimeException|\DomainException) {
                // The checkout keeps its protection and stays due for the next cycle.
                ++$failed;

                continue;
            }
            ++$processed;
        }

        return ['reconciled' => $processed, 'failed' => $failed];
    }

    public function reconcile(string $checkoutId, \DateTimeImmutable $now, string $worker, ?string $operationId = null): HostedPaymentState
    {
        $this->provider->assertConfigured();
        $claim = $this->store->claim($checkoutId, $now, $worker, $operationId);
        if ($claim->closed) {
            return HostedPaymentState::Closed;
        }
        try {
            $state = null === $claim->sessionReference ? HostedPaymentState::Unknown : $this->provider->inspect($claim);
            if (!$claim->attempted) {
                $state = ($claim->cancellationRequested || $now >= $claim->deadline) ? HostedPaymentState::Closed : HostedPaymentState::Open;
            }
            if (HostedPaymentState::Open === $state && ($claim->cancellationRequested || $now >= $claim->deadline)) {
                $this->provider->expire($claim, 'checkout-expire:'.$claim->checkoutId);
                $state = $this->provider->inspect($claim);
            }
        } catch (\RuntimeException) {
            $state = HostedPaymentState::Unknown;
        }
        $this->store->finish($claim, $state, $now);

        return $state;
    }
}
