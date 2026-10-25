<?php

declare(strict_types=1);

namespace App\Application\Newsletter;

interface NewsletterTokens
{
    public function verify(string $token): NewsletterUnsubscribeAuthority;

    public function issue(string $subscriptionId, \DateTimeImmutable $joinedAt): string;
}
