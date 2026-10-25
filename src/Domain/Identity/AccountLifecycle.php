<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final readonly class AccountLifecycle
{
    public string $reason;

    public function __construct(public string $state, string $reason)
    {
        if (!in_array($state, ['active', 'suspended'], true)) {
            throw new \InvalidArgumentException('État du compte invalide.');
        }
        $this->reason = trim($reason);
        if ('' === $this->reason) {
            throw new \InvalidArgumentException('Un motif est obligatoire.');
        }
    }

    public function transitionFrom(string $state): ?string
    {
        return in_array($state, ['active', 'suspended'], true) && $state !== $this->state ? $this->state : null;
    }
}
