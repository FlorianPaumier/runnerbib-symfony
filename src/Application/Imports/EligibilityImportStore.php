<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Domain\Imports\ImportMode;

interface EligibilityImportStore
{
    public function lockScope(string $accountId, string $raceId): void;

    /**
     * @param list<array{row:int,status:string,bib?:string,email?:string,name?:?string,reason?:string}> $rows
     *
     * @return list<array{row:int,status:string,bib?:string,reason?:string}>
     */
    public function persist(string $accountId, string $raceId, string $importId, array $rows, \DateTimeImmutable $now, ImportMode $mode): array;
}
