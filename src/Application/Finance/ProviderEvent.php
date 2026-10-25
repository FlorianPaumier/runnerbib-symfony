<?php

declare(strict_types=1);

namespace App\Application\Finance;

final readonly class ProviderEvent
{
    public function __construct(public string $eventReference, public \DateTimeImmutable $receivedAt, public string $eventType, public ?string $checkoutId, public string $id, public int $generation)
    {
    }
}
