<?php

declare(strict_types=1);

namespace App\Application\Checkout;

use App\Application\Identity\CurrentIdentity;

final readonly class FindCheckoutStatus
{
    public function __construct(private CurrentIdentity $identity, private CheckoutStatusStore $store)
    {
    }

    public function find(string $checkoutId): ?CheckoutStatus
    {
        $buyerId = $this->identity->accountId();

        return null === $buyerId ? null : $this->store->forBuyer($checkoutId, $buyerId);
    }

    public function findBySession(string $sessionReference): ?CheckoutStatus
    {
        $buyerId = $this->identity->accountId();

        return null === $buyerId || '' === $sessionReference ? null : $this->store->forBuyerBySession($sessionReference, $buyerId);
    }
}
