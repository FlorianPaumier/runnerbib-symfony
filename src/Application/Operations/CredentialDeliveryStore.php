<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface CredentialDeliveryStore
{
    public function claim(string $effectKey, string $messageId, \DateTimeImmutable $now): bool;

    public function accept(string $effectKey, \DateTimeImmutable $now): void;

    public function uncertain(string $effectKey, \DateTimeImmutable $now): void;

    public function expire(string $effectKey, \DateTimeImmutable $now): void;
}
