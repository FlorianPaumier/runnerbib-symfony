<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\AccountLifecycle;

final readonly class AdministerAccounts
{
    public function __construct(private CurrentIdentity $identity, private AccountLifecycleStore $accounts, private IdentityEffects $effects)
    {
    }

    public function change(string $targetId, string $state, string $reason): bool
    {
        $actorId = $this->identity->accountId();
        if (null === $actorId) {
            return false;
        }
        try {
            $change = new AccountLifecycle($state, $reason);
        } catch (\InvalidArgumentException $error) {
            throw new InvalidLifecycleChange($error->getMessage(), previous: $error);
        }

        return $this->accounts->change($actorId, $targetId, $change, $this->effects->newId(), $this->effects->now());
    }
}
