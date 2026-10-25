<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface OutboxEventHandler
{
    public function supports(string $eventType): bool;

    public function __invoke(OutboxMessage $message): void;
}
