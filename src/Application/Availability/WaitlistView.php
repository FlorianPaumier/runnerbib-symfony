<?php

declare(strict_types=1);

namespace App\Application\Availability;

final readonly class WaitlistView
{
    public function __construct(public string $id, public string $raceId, public string $accountId, public bool $emailEnabled, public bool $inAppEnabled)
    {
    }
}
