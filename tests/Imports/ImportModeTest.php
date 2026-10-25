<?php

declare(strict_types=1);

namespace App\Tests\Imports;

use App\Domain\Imports\ImportMode;
use PHPUnit\Framework\TestCase;

final class ImportModeTest extends TestCase
{
    public function testDefaultModeNeverRequestsUpdatesAndApprovedSelectionIsExplicit(): void
    {
        self::assertSame([], (new ImportMode('skip_invalid', [], null))->selectedFields);
        self::assertSame(['email'], (new ImportMode('update', ['email'], ['name', 'email']))->selectedFields);
    }

    /** @param array<array-key,string> $selection
     * @param array<array-key,string>|null $approved
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidModes')]
    public function testInvalidOrProtectedUpdateSelectionIsRejected(string $mode, array $selection, ?array $approved): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImportMode($mode, $selection, $approved);
    }

    /** @return iterable<string,array{string,array<array-key,string>,?array<array-key,string>}> */
    public static function invalidModes(): iterable
    {
        yield 'unknown mode' => ['overwrite', [], ['name']];
        yield 'implicit update' => ['skip_invalid', ['name'], ['name']];
        yield 'no selected fields' => ['update', [], ['name']];
        yield 'protected owner' => ['update', ['current_owner_account_id'], ['name']];
        yield 'unapproved email' => ['update', ['email'], ['name']];
        yield 'duplicate fields' => ['update', ['name', 'name'], ['name']];
        yield 'associative fields' => ['update', ['chosen' => 'name'], ['name']];
    }

    /** @param array<array-key,string>|null $approved */
    #[\PHPUnit\Framework\Attributes\DataProvider('unconfiguredModes')]
    public function testAbsentOrUnsafeApprovedConfigurationFailsClosed(?array $approved): void
    {
        $this->expectException(\LogicException::class);
        new ImportMode('update', ['name'], $approved);
    }

    /** @return iterable<string,array{?array<array-key,string>}> */
    public static function unconfiguredModes(): iterable
    {
        yield 'missing policy' => [null];
        yield 'protected policy' => [['current_owner_account_id']];
        yield 'associative policy' => [['chosen' => 'name']];
    }
}
