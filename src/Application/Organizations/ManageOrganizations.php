<?php

declare(strict_types=1);

namespace App\Application\Organizations;

use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Domain\Organizations\OrganizationPolicy;

final readonly class ManageOrganizations
{
    public function __construct(private OrganizationStore $store, private AuditWriter $audit)
    {
    }

    public function request(string $id, string $name, string $ownerAccountId, string $membershipId, \DateTimeImmutable $now): void
    {
        OrganizationPolicy::requireName($name);
        $name = trim($name);
        $this->store->transaction(function () use ($id, $name, $ownerAccountId, $membershipId, $now): void {
            OrganizationPolicy::requireActive($this->store->actor($ownerAccountId));
            $this->store->create($id, $name);
            $this->store->addMembership($membershipId, $id, $ownerAccountId, 'owner', $now);
            $this->appendAudit($ownerAccountId, $id, 'organization.requested', 'owner', $now);
        });
    }

    public function get(string $actorAccountId, string $id): OrganizationView
    {
        OrganizationPolicy::requireMember($this->store->actor($actorAccountId), $this->store->membershipRole($id, $actorAccountId));

        $organization = $this->store->get($id);
        $role = $this->store->membershipRole($id, $actorAccountId);
        assert(is_string($role));

        return new OrganizationView($organization->id, $organization->name, $organization->approvalState, $organization->ownerAccountId, $organization->version, $role, 'owner' === $role);
    }

    public function addManager(string $actorAccountId, string $organizationId, string $accountId, string $membershipId, \DateTimeImmutable $now): void
    {
        $this->store->transaction(function () use ($actorAccountId, $organizationId, $accountId, $membershipId, $now): void {
            $this->store->lockOrganization($organizationId);
            OrganizationPolicy::requireMember($this->store->actor($actorAccountId), $this->store->membershipRole($organizationId, $actorAccountId), true);
            OrganizationPolicy::requireApproved($this->store->get($organizationId));
            OrganizationPolicy::requireActive($this->store->actor($accountId));
            $this->store->addMembership($membershipId, $organizationId, $accountId, 'manager', $now);
            $this->appendAudit($actorAccountId, $organizationId, 'organization.manager_added', 'owner', $now);
        });
    }

    /** @return list<\App\Domain\Organizations\Membership> */
    public function history(string $actorAccountId, string $organizationId): array
    {
        $this->get($actorAccountId, $organizationId);

        return $this->store->history($organizationId);
    }

    public function transferOwnership(string $actorAccountId, string $organizationId, string $newOwnerAccountId, string $oldOwnerMembershipId, string $newOwnerMembershipId, \DateTimeImmutable $now): void
    {
        $this->store->transaction(function () use ($actorAccountId, $organizationId, $newOwnerAccountId, $oldOwnerMembershipId, $newOwnerMembershipId, $now): void {
            $this->store->lockOrganization($organizationId);
            OrganizationPolicy::requireMember($this->store->actor($actorAccountId), $this->store->membershipRole($organizationId, $actorAccountId), true);
            OrganizationPolicy::requireDistinctOwner($actorAccountId, $newOwnerAccountId);
            OrganizationPolicy::requireMember($this->store->actor($newOwnerAccountId), $this->store->membershipRole($organizationId, $newOwnerAccountId));
            $this->store->endMembership($organizationId, $actorAccountId, $now);
            $this->store->endMembership($organizationId, $newOwnerAccountId, $now);
            $this->store->addMembership($oldOwnerMembershipId, $organizationId, $actorAccountId, 'manager', $now);
            $this->store->addMembership($newOwnerMembershipId, $organizationId, $newOwnerAccountId, 'owner', $now);
            $this->appendAudit($actorAccountId, $organizationId, 'organization.owner_transferred', 'owner', $now);
        });
    }

    public function removeManager(string $actorAccountId, string $organizationId, string $accountId, \DateTimeImmutable $now): void
    {
        $this->store->transaction(function () use ($actorAccountId, $organizationId, $accountId, $now): void {
            $this->store->lockOrganization($organizationId);
            OrganizationPolicy::requireMember($this->store->actor($actorAccountId), $this->store->membershipRole($organizationId, $actorAccountId), true);
            OrganizationPolicy::requireManagerRole($this->store->membershipRole($organizationId, $accountId));
            $this->store->endMembership($organizationId, $accountId, $now);
            $this->appendAudit($actorAccountId, $organizationId, 'organization.manager_removed', 'owner', $now);
        });
    }

    public function mine(string $accountId): ?OrganizationView
    {
        OrganizationPolicy::requireActive($this->store->actor($accountId));
        $id = $this->store->activeOrganizationId($accountId);

        return null === $id ? null : $this->get($accountId, $id);
    }

    public function approve(string $actorAccountId, string $id, \DateTimeImmutable $now): void
    {
        $this->store->transaction(function () use ($actorAccountId, $id, $now): void {
            $this->store->lockOrganization($id);
            OrganizationPolicy::requireAdministrator($this->store->actor($actorAccountId));
            $organization = $this->store->get($id);
            OrganizationPolicy::requireActive($this->store->actor($organization->ownerAccountId));
            $this->store->approve($id, $actorAccountId, $now);
            $this->appendAudit($actorAccountId, $id, 'organization.approved', 'administrator', $now);
        });
    }

    public function moderate(string $actorAccountId, string $id, string $state, \DateTimeImmutable $now): void
    {
        $this->store->transaction(function () use ($actorAccountId, $id, $state, $now): void {
            $this->store->lockOrganization($id);
            OrganizationPolicy::requireAdministrator($this->store->actor($actorAccountId));
            OrganizationPolicy::requireModerationState($state);
            $this->store->get($id);
            $this->store->setApprovalState($id, $state);
            $this->appendAudit($actorAccountId, $id, 'organization.'.$state, 'administrator', $now);
        });
    }

    private function appendAudit(string $actorAccountId, string $organizationId, string $action, string $authorityRole, \DateTimeImmutable $now): void
    {
        $actor = $this->store->actor($actorAccountId);
        // The caller already checked the actor authority in the same transaction.
        assert(null !== $actor);
        $authority = json_encode(['account_roles' => $actor->roles, 'organization_role' => $authorityRole], JSON_THROW_ON_ERROR);
        $this->audit->append(new AuditEntry($this->store->newAuditId(), $actorAccountId, null, $authority, $action, 'organization', $organizationId, null, $now, 'succeeded'));
    }
}
