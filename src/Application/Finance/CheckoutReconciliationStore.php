<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Domain\Finance\HostedPaymentState;

interface CheckoutReconciliationStore
{
    /** @return list<string> */
    public function due(\DateTimeImmutable $now, int $limit): array;

    public function claim(string $checkoutId, \DateTimeImmutable $now, string $worker, ?string $operationId = null): CheckoutReconciliation;

    public function finish(CheckoutReconciliation $claim, HostedPaymentState $state, \DateTimeImmutable $now): void;
}
