<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use App\Domain\Finance\FeePolicy;
use App\Domain\Finance\FeeQuote;

final class CartQuote
{
    /** @var array<string, FeeQuote> */
    private array $items = [];

    public function __construct(private readonly string $buyerId, private readonly FeePolicy $fees)
    {
    }

    public function add(string $raceId, string $sellerId, int $priceCents): void
    {
        if ($sellerId === $this->buyerId) {
            throw new \DomainException('A buyer cannot buy their own listing.');
        }
        if (isset($this->items[$raceId])) {
            throw new \DomainException('Only one bib per race is allowed.');
        }
        $quote = $this->fees->quote($priceCents);
        if ($this->buyerTotalCents() > PHP_INT_MAX - $quote->buyerTotalCents) {
            throw new \OverflowException('Cart total exceeds supported integer cents.');
        }
        $this->items[$raceId] = $quote;
    }

    public function priceCents(): int
    {
        return array_sum(array_map(static fn (FeeQuote $quote): int => $quote->priceCents, $this->items));
    }

    public function buyerFeeCents(): int
    {
        return array_sum(array_map(static fn (FeeQuote $quote): int => $quote->buyerFeeCents, $this->items));
    }

    public function buyerTotalCents(): int
    {
        return array_sum(array_map(static fn (FeeQuote $quote): int => $quote->buyerTotalCents, $this->items));
    }
}
