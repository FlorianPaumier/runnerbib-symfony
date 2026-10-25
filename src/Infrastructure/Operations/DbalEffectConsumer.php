<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\EffectConsumer;
use Doctrine\DBAL\Connection;

final readonly class DbalEffectConsumer implements EffectConsumer
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param \Closure(): void $effect */
    public function consume(string $consumer, string $effectKey, \DateTimeImmutable $completedAt, \Closure $effect): bool
    {
        return $this->connection->transactional(function () use ($consumer, $effectKey, $completedAt, $effect): bool {
            $inserted = $this->connection->executeStatement('INSERT INTO consumed_effects(consumer,effect_key,completed_at) VALUES (?,?,?) ON CONFLICT DO NOTHING', [$consumer, $effectKey, $completedAt->format('Y-m-d H:i:s.uP')]);
            if (0 === $inserted) {
                return false;
            }
            $effect();

            return true;
        });
    }
}
