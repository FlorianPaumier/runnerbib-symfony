<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface OutboxWriter
{
    public function append(OutboxMessage $message): void;
}
