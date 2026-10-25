<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\MessageDispatcher;
use App\Application\Operations\OutboxMessage;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerMessageDispatcher implements MessageDispatcher
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function dispatch(OutboxMessage $message): void
    {
        $this->bus->dispatch($message);
    }
}
