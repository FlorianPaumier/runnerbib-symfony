<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final readonly class Actor
{
    /** @param list<string> $roles */
    public function __construct(public string $accountId, public array $roles)
    {
    }

    public function canAccessAccount(string $accountId): bool
    {
        return $accountId === $this->accountId || in_array('admin', $this->roles, true) || $this->canExecuteFinancialAction();
    }

    public function canExecuteFinancialAction(): bool
    {
        return in_array('super_admin', $this->roles, true);
    }

    public function canAdministerAccounts(): bool
    {
        return in_array('admin', $this->roles, true) || $this->canExecuteFinancialAction();
    }
}
