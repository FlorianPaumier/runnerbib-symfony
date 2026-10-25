<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /** @param list<string> $roles */
    public function __construct(public string $accountId, private string $email, private ?string $password, private array $roles, private int $version)
    {
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('Missing account email.');
        }

        return $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getRoles(): array
    {
        return array_map(static fn (string $role): string => match ($role) {
            'admin' => 'ROLE_ADMIN', 'super_admin' => 'ROLE_SUPER_ADMIN', default => 'ROLE_USER',
        }, $this->roles);
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self && $user->accountId === $this->accountId && $user->version === $this->version && $user->password === $this->password && $user->roles === $this->roles;
    }
}
