<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\OutboxEventHandler;
use App\Application\Operations\OutboxMessage;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class OutboxMessageRouter
{
    /** @param iterable<OutboxEventHandler> $handlers */
    public function __construct(#[AutowireIterator('app.outbox_event_handler')] private iterable $handlers)
    {
    }

    public function __invoke(OutboxMessage $message): void
    {
        $selected = null;
        foreach ($this->handlers as $handler) {
            if (!$handler->supports($message->eventType)) {
                continue;
            }
            if (null !== $selected) {
                throw new \InvalidArgumentException('Unsupported or ambiguous outbox event type');
            }
            $selected = $handler;
        }
        if (null === $selected) {
            throw new \InvalidArgumentException('Unsupported or ambiguous outbox event type');
        }
        $selected($message);
    }
}
