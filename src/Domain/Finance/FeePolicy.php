<?php

declare(strict_types=1);

namespace App\Domain\Finance;

final class FeePolicy
{
    public function quote(int $priceCents): FeeQuote
    {
        if ($priceCents <= 500) {
            throw new \InvalidArgumentException('Listing price must leave positive seller net.');
        }

        // Split division into quotient and remainder before multiplying or rounding.
        // This keeps every intermediate value within integer range, even for large prices.
        $buyerFee = max(500, intdiv($priceCents, 20) + intdiv($priceCents % 20 + 10, 20));
        $sellerMargin = max(500, intdiv($priceCents, 40) * 3 + intdiv(($priceCents % 40) * 3 + 20, 40));

        if ($priceCents > PHP_INT_MAX - $buyerFee) {
            throw new \OverflowException('Buyer total exceeds supported integer cents.');
        }

        return new FeeQuote($priceCents, $buyerFee, $priceCents + $buyerFee, $sellerMargin, $priceCents - $sellerMargin);
    }
}
