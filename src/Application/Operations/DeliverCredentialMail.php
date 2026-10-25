<?php

declare(strict_types=1);

namespace App\Application\Operations;

final readonly class DeliverCredentialMail
{
    public function __construct(private CredentialDeliveryStore $store, private CredentialMailSender $sender)
    {
    }

    public function deliver(string $effectKey, CredentialMail $mail, \DateTimeImmutable $now): bool
    {
        if ($mail->expiresAt <= $now) {
            $this->store->expire($effectKey, $now);

            return false;
        }
        $messageId = hash('sha256', $effectKey).'@runnerbib.local';
        if (!$this->store->claim($effectKey, $messageId, $now)) {
            return false;
        }
        try {
            $this->sender->send($mail, $messageId);
            $this->store->accept($effectKey, $now);
        } catch (\Throwable) {
            $this->store->uncertain($effectKey, $now);
            throw new UncertainMailDelivery('Credential mail outcome is uncertain; reconcile the stable Message-ID before retrying.');
        }

        return true;
    }
}
