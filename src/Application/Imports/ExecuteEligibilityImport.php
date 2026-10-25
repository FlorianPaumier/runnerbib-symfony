<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Application\Operations\TransactionManager;
use App\Application\Organizations\OrganizationScope;
use App\Domain\Imports\ImportMode;

final readonly class ExecuteEligibilityImport
{
    /** @param list<string>|null $mutableFields */
    public function __construct(private PreviewEligibilityImport $preview, private OrganizationScope $scope, private EligibilityImportStore $imports, private TransactionManager $transactions, private ?array $mutableFields = null)
    {
    }

    /**
     * @param list<string> $updateFields
     *
     * @return list<array{row:int,status:string,bib?:string,reason?:string}>
     */
    public function execute(string $accountId, string $raceId, string $importId, string $csv, \DateTimeImmutable $now, string $mode = 'skip_invalid', array $updateFields = []): array
    {
        $selection = new ImportMode($mode, $updateFields, $this->mutableFields);
        $rows = $this->preview->parse($csv);
        $result = [];
        $this->transactions->run(function () use ($accountId, $raceId, $importId, $rows, $now, $selection, &$result): void {
            $this->imports->lockScope($accountId, $raceId);
            $this->scope->forRace($accountId, $raceId);
            $result = $this->imports->persist($accountId, $raceId, $importId, $rows, $now, $selection);
        });

        return $result;
    }
}
