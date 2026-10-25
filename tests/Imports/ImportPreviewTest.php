<?php

declare(strict_types=1);

namespace App\Tests\Imports;

use App\Application\Imports\PreviewEligibilityImport;
use PHPUnit\Framework\TestCase;

final class ImportPreviewTest extends TestCase
{
    public function testExplicitMappingPreservesBibZerosAndReportsInvalidAndDuplicateRows(): void
    {
        $preview = new PreviewEligibilityImport(['bib' => 'entry', 'email' => 'contact', 'name' => 'holder'], 1024, 10);
        self::assertSame([
            ['row' => 2, 'status' => 'valid', 'bib' => '00123', 'email' => 'runner@example.test', 'name' => "Runner\nName"],
            ['row' => 3, 'status' => 'duplicate', 'bib' => '00123'],
            ['row' => 4, 'status' => 'invalid', 'reason' => 'email'],
        ], $preview->parse("entry,contact,holder\r\n00123, RUNNER@example.test ,\"Runner\nName\"\r\n00123,other@example.test,Other\r\n00456,invalid,Other\r\n"));
    }

    public function testUnconfiguredPolicyFailsClosed(): void
    {
        $this->expectException(\LogicException::class);
        (new PreviewEligibilityImport())->parse('entry,contact');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFiles')]
    public function testConfiguredImportLimitsAndHeadersAreEnforced(string $csv, int $bytes, int $rows): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PreviewEligibilityImport(['bib' => 'entry', 'email' => 'contact'], $bytes, $rows))->parse($csv);
    }

    /** @return iterable<string,array{string,int,int}> */
    public static function invalidFiles(): iterable
    {
        yield 'missing header' => ['entry,missing', 100, 10];
        yield 'duplicate header' => ['entry,contact,entry', 100, 10];
        yield 'byte limit' => ["entry,contact\n00123,runner@example.test", 10, 10];
        yield 'row limit' => ["entry,contact\n00123,runner@example.test\n00456,other@example.test", 100, 1];
        yield 'invalid UTF-8' => ["entry,contact\n\xff,runner@example.test", 100, 10];
    }

    public function testInvalidRowsAreSkippedAndUtf8BomAndOptionalNameAreSupported(): void
    {
        self::assertSame([
            ['row' => 2, 'status' => 'invalid', 'reason' => 'bib'],
            ['row' => 3, 'status' => 'invalid', 'reason' => 'columns'],
            ['row' => 4, 'status' => 'valid', 'bib' => '00123', 'email' => 'runner@example.test', 'name' => null],
        ], (new PreviewEligibilityImport(['bib' => 'entry', 'email' => 'contact'], 200, 10))->parse("\xef\xbb\xbfentry,contact\n,runner@example.test\n00456,runner@example.test,extra\n00123,runner@example.test"));
    }

    /** @param array<string,string>|null $headers */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPolicies')]
    public function testEachMissingOrInvalidPolicyPartFailsClosed(?array $headers, ?int $bytes, ?int $rows): void
    {
        $this->expectException(\LogicException::class);
        (new PreviewEligibilityImport($headers, $bytes, $rows))->parse('');
    }

    /** @return iterable<array{array<string,string>|null,?int,?int}> */
    public static function invalidPolicies(): iterable
    {
        yield [null, 100, 10];
        yield [['bib' => 'entry', 'email' => 'contact'], null, 10];
        yield [['bib' => 'entry', 'email' => 'contact'], 100, null];
        yield [['bib' => 'entry', 'email' => 'contact'], 0, 10];
        yield [['bib' => 'entry', 'email' => 'contact'], 100, 0];
        yield [['bib' => 'entry'], 100, 10];
        yield [['bib' => 'entry', 'email' => 'contact', 'extra' => 'other'], 100, 10];
        yield [['bib' => 'entry', 'email' => 'entry'], 100, 10];
        yield [['bib' => '', 'email' => 'contact'], 100, 10];
    }

    public function testExactByteAndRowLimitsAreInclusive(): void
    {
        self::assertSame([['row' => 2, 'status' => 'valid', 'bib' => '1', 'email' => 'x@y.fr', 'name' => null]], (new PreviewEligibilityImport(['bib' => 'a', 'email' => 'b'], 12, 1))->parse("a,b\n1,x@y.fr"));
    }

    public function testAOneBytePolicyIsConfiguredEvenThoughTheFileHasNoHeaders(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PreviewEligibilityImport(['bib' => 'a', 'email' => 'b'], 1, 1))->parse('');
    }
}
