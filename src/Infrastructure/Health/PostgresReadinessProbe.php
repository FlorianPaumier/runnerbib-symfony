<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use App\Application\Health\ReadinessProbe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

final readonly class PostgresReadinessProbe implements ReadinessProbe
{
    public function __construct(private Connection $connection)
    {
    }

    public function isReady(): bool
    {
        try {
            return in_array($this->connection->executeQuery('SELECT 1')->fetchOne(), [1, '1'], true);
        } catch (Exception) {
            return false;
        }
    }
}
