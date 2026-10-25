<?php

declare(strict_types=1);

namespace App\Application\Checkout;

final readonly class CheckoutStatus
{
    public function __construct(public string $checkoutId, public string $state, public int $totalCents)
    {
    }
}
