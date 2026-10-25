<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Inventory\ProofRejection;

final readonly class ReviewOwnershipProof
{
    public function __construct(private OwnershipProofStore $proofs)
    {
    }

    /** Reviewer identity comes from the authenticated execution context. */
    public function reject(string $reviewerId, string $proofId, int $expectedVersion, string $reason, string $auditId, \DateTimeImmutable $now): bool
    {
        return $this->proofs->reject($reviewerId, $proofId, $expectedVersion, new ProofRejection($reason), $auditId, $now);
    }
}
