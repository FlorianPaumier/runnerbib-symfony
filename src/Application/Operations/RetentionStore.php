<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface RetentionStore
{
    /**
     * Deletes the records that fell out of their retention window.
     *
     * @param array<string,\DateTimeImmutable> $cutoffs
     *
     * @return array<string,int> deleted rows per table
     */
    public function purge(array $cutoffs): array;
}
