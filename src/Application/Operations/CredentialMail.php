<?php

declare(strict_types=1);

namespace App\Application\Operations;

final readonly class CredentialMail
{
    public function __construct(public string $email, public string $purpose, public string $token, public \DateTimeImmutable $expiresAt)
    {
    }
}
