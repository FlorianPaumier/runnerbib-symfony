<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class AccountProfile
{
    public function __construct(public string $email, public ?string $displayName, public bool $optionalEmailEnabled, public bool $optionalInAppEnabled, public int $version)
    {
    }
}
