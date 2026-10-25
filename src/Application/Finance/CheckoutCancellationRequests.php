<?php

declare(strict_types=1);

namespace App\Application\Finance;

interface CheckoutCancellationRequests
{
    /** Requires the owning transaction to hold and revalidate all affected race locks before account locks. */
    public function requestBuyer(string $buyerId, \DateTimeImmutable $now): void;
}
