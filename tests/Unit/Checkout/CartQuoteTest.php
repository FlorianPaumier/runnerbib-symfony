<?php

declare(strict_types=1);

namespace App\Tests\Unit\Checkout;

use App\Domain\Checkout\CartQuote;
use App\Domain\Finance\FeePolicy;
use PHPUnit\Framework\TestCase;

final class CartQuoteTest extends TestCase
{
    public function testTotalsRoundEachOfferIndependentlyAcrossRacesAndSellers(): void
    {
        $cart = new CartQuote('buyer', new FeePolicy());
        $cart->add('race-1', 'seller-1', 10000);
        $cart->add('race-2', 'seller-2', 20000);
        self::assertSame(30000, $cart->priceCents());
        self::assertSame(1500, $cart->buyerFeeCents());
        self::assertSame(31500, $cart->buyerTotalCents());
    }

    public function testMinimumFeesAreAppliedToEachItemRatherThanCartSubtotal(): void
    {
        $cart = new CartQuote('buyer', new FeePolicy());
        self::assertSame(0, $cart->priceCents());
        self::assertSame(0, $cart->buyerFeeCents());
        self::assertSame(0, $cart->buyerTotalCents());
        $cart->add('race-1', 'seller', 501);
        $cart->add('race-2', 'seller', 501);
        self::assertSame(1002, $cart->priceCents());
        self::assertSame(1000, $cart->buyerFeeCents());
        self::assertSame(2002, $cart->buyerTotalCents());
    }

    public function testBuyerCannotAddTheirOwnOffer(): void
    {
        $cart = new CartQuote('buyer', new FeePolicy());
        $this->expectException(\DomainException::class);
        $cart->add('race', 'buyer', 10000);
    }

    public function testSecondBibForSameRaceCannotReplaceExistingItem(): void
    {
        $cart = new CartQuote('buyer', new FeePolicy());
        $cart->add('race', 'seller-1', 10000);
        try {
            $cart->add('race', 'seller-2', 20000);
            self::fail('Duplicate race was accepted.');
        } catch (\DomainException) {
            self::assertSame(10500, $cart->buyerTotalCents());
        }
    }

    public function testCartCanReachTheExactSupportedIntegerLimit(): void
    {
        $cart = new CartQuote('buyer', new FeePolicy());
        $cart->add('race-1', 'seller', 4611686018427387903);
        $cart->add('race-2', 'seller', 4172477826196208104);
        self::assertSame(9223372036854775807, $cart->buyerTotalCents());
    }

    public function testAddingAnItemCannotOverflowTheWholeCart(): void
    {
        $cart = new CartQuote('buyer', new FeePolicy());
        $cart->add('race-1', 'seller-1', intdiv(PHP_INT_MAX, 2));
        $this->expectException(\OverflowException::class);
        $cart->add('race-2', 'seller-2', intdiv(PHP_INT_MAX, 2));
    }
}
