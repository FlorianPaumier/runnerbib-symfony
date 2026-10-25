<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance;

use App\Domain\Finance\SellerIdentity;
use PHPUnit\Framework\TestCase;

final class SellerIdentityTest extends TestCase
{
    public function testActualSellerCountryAndKnownEmailAreRetained(): void
    {
        $identity = new SellerIdentity('DE', 'runner@example.test');
        self::assertSame('DE', $identity->country);
        self::assertSame('runner@example.test', $identity->email);
    }

    public function testMissingCountryFailsClosedInsteadOfDefaultingToFrance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SellerIdentity('', 'runner@example.test');
    }

    public function testMalformedCountryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SellerIdentity("FR\n", 'runner@example.test');
    }

    public function testMissingKnownEmailIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SellerIdentity('FR', '');
    }
}
