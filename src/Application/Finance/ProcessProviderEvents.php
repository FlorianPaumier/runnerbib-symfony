<?php

declare(strict_types=1);

namespace App\Application\Finance;

final readonly class ProcessProviderEvents
{
    public function __construct(private ProviderEventInbox $inbox, private ReconcileCheckout $reconciliation)
    {
    }

    /**
     * Refreshes the checkout state of the events that arrived from the payment provider.
     *
     * @return array{processed:int,ignored:int,failed:int}
     */
    public function processDue(\DateTimeImmutable $now, string $worker, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000.');
        }
        $processed = 0;
        $ignored = 0;
        $failed = 0;
        foreach ($this->inbox->claimDue($now, $worker, $limit) as $event) {
            if (null === $event->checkoutId) {
                $this->inbox->complete($event, $worker, $now);
                ++$ignored;

                continue;
            }
            try {
                $this->reconciliation->reconcile($event->checkoutId, $now, 'provider-event:'.$worker);
            } catch (\RuntimeException|\DomainException) {
                $this->inbox->retry($event, $worker, $now, $now->modify('+60 seconds'));
                ++$failed;

                continue;
            }
            $this->inbox->complete($event, $worker, $now);
            ++$processed;
        }

        return ['processed' => $processed, 'ignored' => $ignored, 'failed' => $failed];
    }
}
