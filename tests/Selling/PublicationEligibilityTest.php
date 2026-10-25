<?php

declare(strict_types=1);

namespace App\Tests\Selling;

use App\Domain\Selling\PublicationEligibility;
use PHPUnit\Framework\TestCase;

final class PublicationEligibilityTest extends TestCase
{
    public function testOnlyCurrentVerifiedReadyOwnerCanPublishBeforeDeadline(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T12:00:00Z');
        self::assertFalse((new PublicationEligibility(true, true, true, true, true, null, null, null, true))->allows(10000, $now));
        self::assertTrue((new PublicationEligibility(true, true, true, true, true, $now->modify('+1 day'), null, null, true))->allows(10000, $now));
        self::assertFalse((new PublicationEligibility(true, true, true, true, true, $now->modify('+1 day'), null, null, false))->allows(10000, $now));
        self::assertFalse((new PublicationEligibility(false, true, true, true, true, $now->modify('+1 day'), null, null, true))->allows(10000, $now));
        self::assertFalse((new PublicationEligibility(true, false, true, true, true, $now->modify('+1 day'), null, null, true))->allows(10000, $now));
        self::assertFalse((new PublicationEligibility(true, true, false, true, true, $now->modify('+1 day'), null, null, true))->allows(10000, $now));
        self::assertFalse((new PublicationEligibility(true, true, true, false, true, $now->modify('+1 day'), null, null, true))->allows(10000, $now));
        self::assertFalse((new PublicationEligibility(true, true, true, true, false, $now->modify('+1 day'), null, null, true))->allows(10000, $now));
        self::assertFalse((new PublicationEligibility(true, true, true, true, true, $now, null, null, true))->allows(10000, $now));
        self::assertTrue((new PublicationEligibility(true, true, true, true, true, $now->modify('+1 second'), 10000, 10000, true))->allows(10000, $now));
        self::assertFalse((new PublicationEligibility(true, true, true, true, true, $now->modify('+1 day'), 10001, null, true))->allows(10000, $now));
        self::assertFalse((new PublicationEligibility(true, true, true, true, true, $now->modify('+1 day'), null, 9999, true))->allows(10000, $now));
    }
}
