<?php

declare(strict_types=1);

namespace App\Application\Newsletter;

interface NewsletterMailSender
{
    public function send(NewsletterSubscription $subscription, string $unsubscribeToken, string $messageId): void;
}
