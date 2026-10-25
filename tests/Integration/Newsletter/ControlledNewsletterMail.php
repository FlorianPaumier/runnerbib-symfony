<?php

declare(strict_types=1);

namespace App\Tests\Integration\Newsletter;

use App\Application\Newsletter\NewsletterMailSender;
use App\Application\Newsletter\NewsletterSubscription;

final class ControlledNewsletterMail implements NewsletterMailSender
{
    /** @var list<array{email:string,token:string,message_id:string}> */
    public array $sent = [];
    public bool $fail = false;

    public function send(NewsletterSubscription $subscription, string $unsubscribeToken, string $messageId): void
    {
        $this->sent[] = ['email' => $subscription->email, 'token' => $unsubscribeToken, 'message_id' => $messageId];
        if ($this->fail) {
            throw new \RuntimeException('Private SMTP failure details.');
        }
    }
}
