<?php

declare(strict_types=1);

namespace App\Application\Identity;

interface CurrentIdentity
{
    public function accountId(): ?string;
}
