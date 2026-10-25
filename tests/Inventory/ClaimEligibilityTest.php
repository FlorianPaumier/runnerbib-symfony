<?php

declare(strict_types=1);

namespace App\Tests\Inventory;

use App\Domain\Inventory\ClaimEligibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClaimEligibilityTest extends TestCase
{
    public function testOnlyVerifiedOriginalHolderCanClaimImportedParticipatingEntry(): void
    {
        self::assertTrue((new ClaimEligibility('active', true, 'participating', 'import', 'Runner@Example.test', 'runner@example.test', 'approved', true, true, null, false))->allows());
        self::assertTrue((new ClaimEligibility('active', true, 'nonparticipating', 'document', null, 'runner@example.test', 'approved', true, true, null, false))->allows());
    }

    /** @return iterable<string,array{string,bool,string,string,?string,string,?string,bool,bool,?string,bool}> */
    public static function denied(): iterable
    {
        yield 'suspended' => ['suspended', true, 'participating', 'import', 'runner@example.test', 'runner@example.test', 'approved', true, true, null, false];
        yield 'unverified' => ['active', false, 'participating', 'import', 'runner@example.test', 'runner@example.test', 'approved', true, true, null, false];
        yield 'missing holder email' => ['active', true, 'participating', 'import', null, 'runner@example.test', 'approved', true, true, null, false];
        yield 'wrong holder' => ['active', true, 'participating', 'import', 'other@example.test', 'runner@example.test', 'approved', true, true, null, false];
        yield 'nonparticipating email only' => ['active', true, 'nonparticipating', 'import', 'runner@example.test', 'runner@example.test', 'approved', true, true, null, false];
        yield 'pending proof' => ['active', true, 'participating', 'import', 'runner@example.test', 'runner@example.test', null, true, true, null, false];
        yield 'stale review' => ['active', true, 'participating', 'import', 'runner@example.test', 'runner@example.test', 'approved', false, true, null, false];
        yield 'another submitter' => ['active', true, 'participating', 'import', 'runner@example.test', 'runner@example.test', 'approved', true, false, null, false];
        yield 'already owned' => ['active', true, 'participating', 'import', 'runner@example.test', 'runner@example.test', 'approved', true, true, 'owner-id', false];
        yield 'another race entry' => ['active', true, 'participating', 'import', 'runner@example.test', 'runner@example.test', 'approved', true, true, null, true];
        yield 'unknown source' => ['active', true, 'nonparticipating', 'unknown', null, 'runner@example.test', 'approved', true, true, null, false];
    }

    #[DataProvider('denied')]
    public function testUnsafeClaimsFail(string $state, bool $verified, string $participation, string $source, ?string $holder, string $email, ?string $decision, bool $current, bool $submitter, ?string $owner, bool $alreadyOwns): void
    {
        self::assertFalse((new ClaimEligibility($state, $verified, $participation, $source, $holder, $email, $decision, $current, $submitter, $owner, $alreadyOwns))->allows());
    }

    public function testImportedHolderAndAccountEmailsAreTrimmedAndCaseInsensitive(): void
    {
        self::assertTrue((new ClaimEligibility('active', true, 'participating', 'import', ' Runner@Example.test ', ' RUNNER@EXAMPLE.TEST ', 'approved', true, true, null, false))->allows());
    }
}
