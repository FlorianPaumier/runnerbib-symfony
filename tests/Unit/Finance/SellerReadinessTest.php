<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance;

use App\Domain\Finance\SellerReadiness;
use PHPUnit\Framework\TestCase;

final class SellerReadinessTest extends TestCase
{
    public function testActiveRecipientTransfersPermitPublicationAndTransfer(): void
    {
        $readiness = SellerReadiness::fromRecipient('active');
        self::assertSame('ready', $readiness->value);
        self::assertTrue($readiness->canPublish());
        self::assertTrue($readiness->canTransfer());
    }

    public function testInactiveAndPendingRecipientsCannotPublishOrReceiveTransfers(): void
    {
        foreach (['pending', 'inactive', 'restricted', 'unrequested', 'future_status', ''] as $status) {
            $readiness = SellerReadiness::fromRecipient($status);
            self::assertSame('pending' === $status ? 'pending' : 'restricted', $readiness->value);
            self::assertFalse($readiness->canPublish());
            self::assertFalse($readiness->canTransfer());
        }
    }

    public function testActiveCapabilityWithOutstandingRequirementsRemainsPending(): void
    {
        $readiness = SellerReadiness::fromRecipient('active', true);
        self::assertSame('pending', $readiness->value);
        self::assertFalse($readiness->canPublish());
        self::assertFalse($readiness->canTransfer());
    }
}
