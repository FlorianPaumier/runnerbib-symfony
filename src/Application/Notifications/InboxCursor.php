<?php

declare(strict_types=1);

namespace App\Application\Notifications;

final readonly class InboxCursor
{
    public function __construct(public \DateTimeImmutable $createdAt, public string $id)
    {
    }
}
