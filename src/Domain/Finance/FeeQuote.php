<?php

declare(strict_types=1);

namespace App\Domain\Finance;

final readonly class FeeQuote
{
    public string $currency;

    public function __construct(
        public int $priceCents,
        public int $buyerFeeCents,
        public int $buyerTotalCents,
        public int $sellerMarginCents,
        public int $sellerNetCents,
    ) {
        $this->currency = 'EUR';
    }
}
