<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Infrastructure\Identity\SecurityCurrentIdentity;
use App\Infrastructure\Identity\SecurityUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CurrentIdentityTest extends TestCase
{
    public function testAnonymousAndAuthenticatedPrincipalContexts(): void
    {
        $tokens = new TokenStorage();
        $identity = new SecurityCurrentIdentity($tokens);
        self::assertNull($identity->accountId());
        $tokens->setToken(new UsernamePasswordToken(new SecurityUser('account-id', 'runner@example.test', 'hash', ['runner'], 1), 'main', ['ROLE_USER']));
        self::assertSame('account-id', $identity->accountId());
        $tokens->setToken(new UsernamePasswordToken(new \Symfony\Component\Security\Core\User\InMemoryUser('untrusted', null), 'main', ['ROLE_USER']));
        self::assertNull($identity->accountId());
    }
}
