<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Identity\Actor;

final readonly class ProofRejection
{
    public string $reason;

    public function __construct(string $reason)
    {
        $this->reason = trim($reason);
        if ('' === $this->reason) {
            throw new \InvalidArgumentException('Proof rejection requires a reason.');
        }
    }

    public function allows(?Actor $reviewer): bool
    {
        return null !== $reviewer && $reviewer->canAdministerAccounts();
    }
}
