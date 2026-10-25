<?php

declare(strict_types=1);

namespace App\Application\Organizations;

use App\Domain\Organizations\Organization;
use App\Domain\Organizations\OrganizationPolicy;

final readonly class OrganizationScope
{
    public function __construct(private OrganizationStore $store)
    {
    }

    public function forImport(string $actorAccountId, string $importId): Organization
    {
        $raceId = $this->store->raceIdForImport($importId);
        if (null === $raceId) {
            throw new \DomainException('Organization import access denied.');
        }

        return $this->forRace($actorAccountId, $raceId);
    }

    public function forRace(string $actorAccountId, string $raceId): Organization
    {
        $organizationId = $this->store->organizationIdForRace($raceId);
        if (null === $organizationId) {
            throw new \DomainException('Organization race access denied.');
        }
        OrganizationPolicy::requireMember($this->store->actor($actorAccountId), $this->store->membershipRole($organizationId, $actorAccountId));
        $organization = $this->store->get($organizationId);
        OrganizationPolicy::requireApproved($organization);

        return $organization;
    }
}
