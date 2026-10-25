<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Inventory\BibNumber;
use App\Domain\Inventory\ProofRejection;

interface OwnershipProofStore
{
    public function documentForReview(string $reviewerId, string $proofId): ?StoredProof;

    public function canSubmitDocument(string $accountId, string $raceId, BibNumber $bib): bool;

    public function submitDocument(string $accountId, string $raceId, BibNumber $bib, string $proofId, QuarantinedProof $object, string $auditId, \DateTimeImmutable $now): bool;

    public function imported(string $accountId, string $entitlementId, string $proofId, string $auditId, \DateTimeImmutable $now): bool;

    public function reject(string $reviewerId, string $proofId, int $expectedVersion, ProofRejection $rejection, string $auditId, \DateTimeImmutable $now): bool;
}
