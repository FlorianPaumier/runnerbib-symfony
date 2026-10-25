<?php

declare(strict_types=1);

namespace App\Application\Finance;

final readonly class ReceiveProviderEvent
{
    public function __construct(private ProviderEventAuthenticator $authenticator, private ProviderEventInbox $inbox)
    {
    }

    public function receive(string $rawBody, string $signature, \DateTimeImmutable $now): bool
    {
        return $this->inbox->persist($this->authenticator->authenticate($rawBody, $signature, $now), $now);
    }
}
