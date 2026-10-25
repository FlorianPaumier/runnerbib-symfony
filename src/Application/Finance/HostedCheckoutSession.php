<?php

declare(strict_types=1);

namespace App\Application\Finance;

final readonly class HostedCheckoutSession
{
    public function __construct(public string $sessionReference, public ?string $url)
    {
    }
}
