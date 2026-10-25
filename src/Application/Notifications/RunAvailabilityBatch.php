<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Operations\TransactionManager;

final readonly class RunAvailabilityBatch
{
    public function __construct(private AvailabilityBatchStore $store, private TransactionManager $transactions)
    {
    }

    public function runNext(\DateTimeImmutable $now): bool
    {
        $processed = false;
        $this->transactions->run(function () use ($now, &$processed): void { $processed = $this->store->runNext($now); });

        return $processed;
    }
}
