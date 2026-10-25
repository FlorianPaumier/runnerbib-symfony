<?php

declare(strict_types=1);

namespace App\Application\Checkout;

interface CheckoutStatusStore
{
    public function forBuyer(string $checkoutId, string $buyerId): ?CheckoutStatus;

    public function forBuyerBySession(string $sessionReference, string $buyerId): ?CheckoutStatus;
}
