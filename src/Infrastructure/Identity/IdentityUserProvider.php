<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\IdentityStore;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<SecurityUser> */
final readonly class IdentityUserProvider implements UserProviderInterface
{
    public function __construct(private IdentityStore $store)
    {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        $account = $this->store->findActive($identifier);
        if (null === $account) {
            throw new UserNotFoundException('Authentication failed.');
        }

        return new SecurityUser($account['id'], strtolower(trim($identifier)), $account['password_hash'], $account['roles'], $account['version']);
    }

    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException('Unsupported account.');
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }
}
