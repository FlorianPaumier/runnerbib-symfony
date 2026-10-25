<?php

declare(strict_types=1);

namespace App\Application\Finance;

final readonly class AuthenticatedProviderEvent
{
    public function __construct(public string $providerScope, public string $eventReference, public string $protectedPayload)
    {
    }
}
