<?php

declare(strict_types=1);

namespace App\Domain\Organizations;

final readonly class Membership
{
    public function __construct(public string $accountId, public string $role, public ?string $endedAt)
    {
    }
}
