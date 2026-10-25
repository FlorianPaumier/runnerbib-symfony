<?php

declare(strict_types=1);

namespace App\Application\Newsletter;

interface NewsletterStore
{
    public function current(NewsletterUnsubscribeAuthority $authority): ?NewsletterSubscription;

    public function unsubscribe(NewsletterUnsubscribeAuthority $authority, ?string $reason, \DateTimeImmutable $now): bool;

    public function subscribe(string $requestId, string $normalizedEmail, \DateTimeImmutable $now): ?NewsletterSubscription;
}
