<?php

declare(strict_types=1);

namespace App\Domain\Organizations;

use App\Domain\Identity\Actor;

final class OrganizationPolicy
{
    public static function requireName(string $name): void
    {
        if (1 !== preg_match('/\\S/u', $name)) {
            throw new \InvalidArgumentException('Organization name is required.');
        }
    }

    public static function requireManagerRole(?string $role): void
    {
        if ('manager' !== $role) {
            throw new \DomainException('An active manager membership is required.');
        }
    }

    public static function requireModerationState(string $state): void
    {
        if (!in_array($state, ['rejected', 'suspended'], true)) {
            throw new \InvalidArgumentException('Unsupported organization moderation state.');
        }
    }

    public static function requireDistinctOwner(string $currentOwner, string $newOwner): void
    {
        if ($currentOwner === $newOwner) {
            throw new \DomainException('New owner must be a different member.');
        }
    }

    public static function requireApproved(Organization $organization): void
    {
        if ('approved' !== $organization->approvalState) {
            throw new \DomainException('Organization approval required.');
        }
    }

    public static function requireActive(?Actor $actor): void
    {
        if (null === $actor) {
            throw new \DomainException('Active verified account required.');
        }
    }

    public static function requireMember(?Actor $actor, ?string $role, bool $ownerOnly = false): void
    {
        if (null === $actor || null === $role || ($ownerOnly && 'owner' !== $role)) {
            throw new \DomainException('Organization access denied.');
        }
    }

    public static function requireAdministrator(?Actor $actor): void
    {
        if (null === $actor || (!in_array('admin', $actor->roles, true) && !in_array('super_admin', $actor->roles, true))) {
            throw new \DomainException('Organization approval requires an active verified administrator.');
        }
    }
}
