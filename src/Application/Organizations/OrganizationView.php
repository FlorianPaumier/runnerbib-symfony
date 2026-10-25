<?php

declare(strict_types=1);

namespace App\Application\Organizations;

final readonly class OrganizationView
{
    public function __construct(public string $id, public string $name, public string $approvalState, public string $ownerAccountId, public int $version, public string $memberRole, public bool $canManageMembership)
    {
    }
}
