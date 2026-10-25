<?php

declare(strict_types=1);

namespace App\Domain\Organizations;

final readonly class Organization
{
    public function __construct(public string $id, public string $name, public string $approvalState, public string $ownerAccountId, public int $version)
    {
    }
}
