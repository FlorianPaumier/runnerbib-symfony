<?php

declare(strict_types=1);

namespace App\Application\Identity;

interface IdentityEffects
{
    public function newId(): string;

    public function token(): string;

    public function hashPassword(string $password): string;

    public function now(): \DateTimeImmutable;

    public function deliver(string $email, string $purpose, string $token, \DateTimeImmutable $expiresAt): void;
}
