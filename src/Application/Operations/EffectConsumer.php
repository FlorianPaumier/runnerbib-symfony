<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface EffectConsumer
{
    /** @param \Closure(): void $effect */
    public function consume(string $consumer, string $effectKey, \DateTimeImmutable $completedAt, \Closure $effect): bool;
}
