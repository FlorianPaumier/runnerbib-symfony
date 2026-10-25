<?php

declare(strict_types=1);

namespace App\Application\Checkout;

final readonly class CheckoutStart
{
    public function __construct(public string $checkoutId, public ?string $paymentUrl)
    {
    }
}
