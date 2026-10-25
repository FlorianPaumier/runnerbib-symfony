<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface MessageDispatcher
{
    public function dispatch(OutboxMessage $message): void;
}
