<?php

declare(strict_types=1);

namespace App\Application\Inventory;

final readonly class ClaimEntitlement
{
    public function __construct(private EntitlementStore $store)
    {
    }

    /** The actor identifier must come from the authenticated execution context. */
    public function execute(string $accountId, string $entitlementId, string $proofId, string $transitionId, \DateTimeImmutable $now): bool
    {
        return $this->store->claim($accountId, $entitlementId, $proofId, $transitionId, $now);
    }
}
