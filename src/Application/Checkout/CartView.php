<?php

declare(strict_types=1);

namespace App\Application\Checkout;

final readonly class CartView
{
    /** @param list<CartItemView> $items */
    public function __construct(public array $items, public int $priceCents, public int $buyerFeeCents, public int $buyerTotalCents, public bool $payable = false)
    {
    }
}
