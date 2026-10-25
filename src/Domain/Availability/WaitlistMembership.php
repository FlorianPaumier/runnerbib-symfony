<?php

declare(strict_types=1);

namespace App\Domain\Availability;

final readonly class WaitlistMembership
{
    public function __construct(public string $id, public string $raceId, public string $accountId, public bool $emailEnabled, public bool $inAppEnabled)
    {
    }
}
