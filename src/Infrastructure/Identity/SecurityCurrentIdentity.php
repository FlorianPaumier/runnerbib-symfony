<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\CurrentIdentity;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class SecurityCurrentIdentity implements CurrentIdentity
{
    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public function accountId(): ?string
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof SecurityUser ? $user->accountId : null;
    }
}
