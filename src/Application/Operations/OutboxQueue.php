<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface OutboxQueue
{
    public function claim(\DateTimeImmutable $now, string $worker): ?ClaimedOutboxMessage;

    public function acknowledge(ClaimedOutboxMessage $claim, \DateTimeImmutable $now): bool;

    /**
     * Defers a failed intent. After the maximum number of attempts the intent is marked failed
     * and is no longer claimed.
     */
    public function retry(ClaimedOutboxMessage $claim, \DateTimeImmutable $nextAttempt, int $maximumAttempts): bool;
}
