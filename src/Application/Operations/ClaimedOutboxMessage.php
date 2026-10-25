<?php

declare(strict_types=1);

namespace App\Application\Operations;

final readonly class ClaimedOutboxMessage
{
    public function __construct(public OutboxMessage $message, public string $worker, public int $generation)
    {
    }
}
