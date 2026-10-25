<?php

declare(strict_types=1);

namespace App\Application\Newsletter;

final readonly class NewsletterUnsubscribeAuthority
{
    public function __construct(public string $subscriptionId, public \DateTimeImmutable $joinedAt)
    {
    }
}
