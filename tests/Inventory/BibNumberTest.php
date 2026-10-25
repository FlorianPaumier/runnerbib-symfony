<?php

declare(strict_types=1);

namespace App\Tests\Inventory;

use App\Domain\Inventory\BibNumber;
use PHPUnit\Framework\TestCase;

final class BibNumberTest extends TestCase
{
    public function testBibIdentityIsTextualAndKeepsLeadingZeros(): void
    {
        self::assertSame('00123', (new BibNumber(' 00123 '))->value);
        self::assertSame('A-001', (new BibNumber('A-001'))->value);
    }

    public function testBlankBibIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BibNumber('   ');
    }
}
