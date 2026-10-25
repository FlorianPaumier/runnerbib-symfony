<?php

declare(strict_types=1);

namespace App\Application\Operations;

final readonly class OutboxMessage
{
    public function __construct(
        public string $id,
        public string $effectKey,
        public string $aggregateKind,
        public string $aggregateId,
        public int $aggregateVersion,
        public string $eventType,
        public int $payloadVersion,
        public string $payload,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
