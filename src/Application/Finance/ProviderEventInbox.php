<?php

declare(strict_types=1);

namespace App\Application\Finance;

interface ProviderEventInbox
{
    public function persist(AuthenticatedProviderEvent $event, \DateTimeImmutable $now): bool;

    /**
     * Claims the events that are due for processing.
     *
     * @return list<ProviderEvent>
     */
    public function claimDue(\DateTimeImmutable $now, string $worker, int $limit): array;

    public function complete(ProviderEvent $event, string $worker, \DateTimeImmutable $now): bool;

    public function retry(ProviderEvent $event, string $worker, \DateTimeImmutable $now, \DateTimeImmutable $nextAttempt): bool;
}
