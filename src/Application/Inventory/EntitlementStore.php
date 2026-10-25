<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Inventory\BibNumber;

interface EntitlementStore
{
    public function recordImported(string $id, string $raceId, string $importId, BibNumber $bibNumber, ?string $originalHolderName, string $originalEmail): bool;

    public function claim(string $accountId, string $entitlementId, string $proofId, string $transitionId, \DateTimeImmutable $now): bool;
}
