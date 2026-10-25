<?php

declare(strict_types=1);

namespace App\Application\Operations;

enum OutboxDispatchOutcome: string
{
    case Dispatched = 'dispatched';
    case Deferred = 'deferred';
    case Empty = 'empty';
}
