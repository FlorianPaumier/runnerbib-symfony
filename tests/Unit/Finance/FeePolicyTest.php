<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance;

use App\Domain\Finance\FeePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeePolicyTest extends TestCase
{
    public function testHundredEuroBibHasAcceptedBuyerAndSellerAmounts(): void
    {
        $quote = (new FeePolicy())->quote(10000);
        self::assertSame('EUR', $quote->currency);
        self::assertSame(10000, $quote->priceCents);
        self::assertSame(500, $quote->buyerFeeCents);
        self::assertSame(10500, $quote->buyerTotalCents);
        self::assertSame(750, $quote->sellerMarginCents);
        self::assertSame(9250, $quote->sellerNetCents);
    }

    public function testZeroSellerNetCannotBeQuoted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FeePolicy())->quote(500);
    }

    public function testBuyerTotalOverflowIsRejected(): void
    {
        $this->expectException(\OverflowException::class);
        (new FeePolicy())->quote(PHP_INT_MAX);
    }

    #[DataProvider('quotes')]
    public function testExactPerBibQuotes(int $price, int $buyerFee, int $total, int $margin, int $net): void
    {
        $quote = (new FeePolicy())->quote($price);
        self::assertSame([$price, $buyerFee, $total, $margin, $net], [$quote->priceCents, $quote->buyerFeeCents, $quote->buyerTotalCents, $quote->sellerMarginCents, $quote->sellerNetCents]);
    }

    /** @return iterable<string, array{int, int, int, int, int}> */
    public static function quotes(): iterable
    {
        yield '200 euro' => [20000, 1000, 21000, 1500, 18500];
        yield 'minimum accepted cent' => [501, 500, 1001, 500, 1];
        yield 'minimum per bib' => [1000, 500, 1500, 500, 500];
        yield 'seller before half cent' => [10019, 501, 10520, 751, 9268];
        yield 'seller half cent' => [10020, 501, 10521, 752, 9268];
        yield 'buyer below half cent' => [10009, 500, 10509, 751, 9258];
        yield 'buyer half cent' => [10010, 501, 10511, 751, 9259];
        yield 'seller minimum threshold' => [6673, 500, 7173, 500, 6173];
        yield 'seller percentage threshold' => [6674, 500, 7174, 501, 6173];
        yield 'large amount without intermediate overflow' => [4000000000000000000, 200000000000000000, 4200000000000000000, 300000000000000000, 3700000000000000000];
        yield 'maximum buyer total' => [8784163844623596007, 439208192231179800, 9223372036854775807, 658812288346769701, 8125351556276826306];
    }

    #[DataProvider('invalidPrices')]
    public function testInvalidPricesCannotLeaveZeroOrNegativeNet(int $price): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FeePolicy())->quote($price);
    }

    /** @return iterable<array{int}> */
    public static function invalidPrices(): iterable
    {
        yield [PHP_INT_MIN];
        yield [-1];
        yield [0];
        yield [499];
    }

    public function testOneCentAboveMaximumBuyerTotalIsRejected(): void
    {
        $this->expectException(\OverflowException::class);
        (new FeePolicy())->quote(8784163844623596008);
    }
}
