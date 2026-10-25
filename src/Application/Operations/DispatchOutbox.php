<?php

declare(strict_types=1);

namespace App\Application\Operations;

final readonly class DispatchOutbox
{
    public function __construct(private OutboxQueue $queue, private MessageDispatcher $dispatcher, private int $maximumAttempts = 5)
    {
    }

    /**
     * Queues one committed intent. A failing intent is deferred and never blocks the intents behind it.
     *
     * @phpstan-impure the dispatch writes to the durable outbox
     */
    public function dispatchNext(\DateTimeImmutable $now, string $worker): OutboxDispatchOutcome
    {
        $claim = $this->queue->claim($now, $worker);
        if (null === $claim) {
            return OutboxDispatchOutcome::Empty;
        }
        try {
            $this->dispatcher->dispatch($claim->message);
        } catch (\Throwable $exception) {
            $this->queue->retry($claim, $now->modify('+60 seconds'), $this->maximumAttempts);

            return OutboxDispatchOutcome::Deferred;
        }

        return $this->queue->acknowledge($claim, $now) ? OutboxDispatchOutcome::Dispatched : OutboxDispatchOutcome::Deferred;
    }
}
