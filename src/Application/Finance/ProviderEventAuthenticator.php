<?php

declare(strict_types=1);

namespace App\Application\Finance;

interface ProviderEventAuthenticator
{
    public function authenticate(string $rawBody, string $signature, \DateTimeImmutable $now): AuthenticatedProviderEvent;
}
