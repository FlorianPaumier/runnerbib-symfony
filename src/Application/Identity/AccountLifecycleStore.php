<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\AccountLifecycle;

interface AccountLifecycleStore
{
    public function change(string $actorId, string $targetId, AccountLifecycle $change, string $auditId, \DateTimeImmutable $now): bool;
}
