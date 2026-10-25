<?php

declare(strict_types=1);

namespace App\Application\Newsletter;

use App\Application\Operations\CredentialDeliveryStore;

final readonly class DeliverNewsletterWelcome
{
    public function __construct(private NewsletterStore $store, private NewsletterTokens $tokens, private CredentialDeliveryStore $journal, private NewsletterMailSender $sender)
    {
    }

    public function deliver(string $effectKey, string $token, \DateTimeImmutable $now): bool
    {
        $subscription = $this->store->current($this->tokens->verify($token));
        if (null === $subscription) {
            $this->journal->expire($effectKey, $now);

            return false;
        }
        $messageId = hash('sha256', $effectKey).'@runnerbib.local';
        if (!$this->journal->claim($effectKey, $messageId, $now)) {
            return false;
        }
        try {
            $this->sender->send($subscription, $token, $messageId);
            $this->journal->accept($effectKey, $now);
        } catch (\Throwable) {
            $this->journal->uncertain($effectKey, $now);
            throw new \App\Application\Operations\UncertainMailDelivery('Newsletter mail outcome is uncertain; reconcile the stable Message-ID before retrying.');
        }

        return true;
    }
}
