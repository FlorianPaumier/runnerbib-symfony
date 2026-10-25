<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Presentation\Twig\EuroExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class EuroFormattingTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('amounts')]
    public function testMoneyRenderingPreservesIntegerCents(int $cents, string $expected): void
    {
        $twig = new Environment(new ArrayLoader(['price' => '{{ cents|euros }}']));
        $twig->addExtension(new EuroExtension());
        self::assertSame($expected, $twig->render('price', ['cents' => $cents]));
    }

    /** @return iterable<array{int,string}> */
    public static function amounts(): iterable
    {
        yield [10500, '105,00 €'];
        yield [0, '0,00 €'];
        yield [1, '0,01 €'];
        yield [-1, '-0,01 €'];
        yield [-9223372036854775807 - 1, '-92 233 720 368 547 758,08 €'];
        yield [9223372036854775807, '92 233 720 368 547 758,07 €'];
    }
}
