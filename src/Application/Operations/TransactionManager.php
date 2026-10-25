<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface TransactionManager
{
    /** @param \Closure(): void $operation */
    public function run(\Closure $operation): void;
}
