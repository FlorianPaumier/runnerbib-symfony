<?php

declare(strict_types=1);

namespace App\Application\Inventory;

final readonly class SubmitOwnershipProof
{
    public function __construct(private OwnershipProofStore $proofs)
    {
    }

    /** Account identity comes from the authenticated execution context. */
    public function imported(string $accountId, string $entitlementId, string $proofId, string $auditId, \DateTimeImmutable $now): bool
    {
        return $this->proofs->imported($accountId, $entitlementId, $proofId, $auditId, $now);
    }
}
