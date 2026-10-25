<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\IdentityEffects;
use App\Application\Operations\CredentialTokenProtector;
use App\Application\Operations\OutboxMessage;
use App\Application\Operations\OutboxWriter;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final readonly class OutboxIdentityEffects implements IdentityEffects
{
    public function __construct(private OutboxWriter $outbox, private PasswordHasherFactoryInterface $hashers, private CredentialTokenProtector $protector)
    {
    }

    public function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    public function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashPassword(string $password): string
    {
        return $this->hashers->getPasswordHasher(SecurityUser::class)->hash($password);
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function deliver(string $email, string $purpose, string $token, \DateTimeImmutable $expiresAt): void
    {
        $id = $this->newId();
        $this->outbox->append(new OutboxMessage($id, 'identity-mail:'.$id, 'identity_mail', $id, 1, 'identity.credential_mail', 1, json_encode(['email' => $email, 'purpose' => $purpose, 'token' => $this->protector->protect($token), 'expires_at' => $expiresAt->format(DATE_ATOM)], JSON_THROW_ON_ERROR), $this->now()));
    }
}
