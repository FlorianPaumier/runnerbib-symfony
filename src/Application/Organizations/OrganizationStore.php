<?php

declare(strict_types=1);

namespace App\Application\Organizations;

use App\Domain\Organizations\Organization;

interface OrganizationStore
{
    /** @param \Closure():void $operation */
    public function transaction(\Closure $operation): void;

    public function actor(string $accountId): ?\App\Domain\Identity\Actor;

    public function membershipRole(string $organizationId, string $accountId): ?string;

    public function lockOrganization(string $id): void;

    public function endMembership(string $organizationId, string $accountId, \DateTimeImmutable $now): void;

    /** @return list<\App\Domain\Organizations\Membership> */
    public function history(string $organizationId): array;

    public function organizationIdForRace(string $raceId): ?string;

    public function activeOrganizationId(string $accountId): ?string;

    public function newAuditId(): string;

    public function setApprovalState(string $id, string $state): void;

    public function raceIdForImport(string $importId): ?string;

    public function create(string $id, string $name): void;

    public function addMembership(string $id, string $organizationId, string $accountId, string $role, \DateTimeImmutable $now): void;

    public function get(string $id): Organization;

    public function approve(string $id, string $actorAccountId, \DateTimeImmutable $now): void;
}
