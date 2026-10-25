<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\TransactionManager;
use Doctrine\DBAL\Connection;

final readonly class DbalTransactionManager implements TransactionManager
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param \Closure(): void $operation */
    public function run(\Closure $operation): void
    {
        $this->connection->transactional($operation);
    }
}
