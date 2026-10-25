<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Operations\CredentialDeliveryStore;

final readonly class DeliverAvailabilityDigest
{
    public function __construct(private AvailabilityDigestStore $store, private CredentialDeliveryStore $journal, private AvailabilityDigestSender $sender)
    {
    }

    /** @param list<string> $notificationIds */
    public function deliver(string $effectKey, string $recipientId, array $notificationIds, \DateTimeImmutable $now): bool
    {
        $digest = $this->store->prepare($recipientId, $notificationIds, $now);
        if (null === $digest) {
            $this->journal->expire($effectKey, $now);

            return false;
        }
        $messageId = hash('sha256', $effectKey).'@runnerbib.local';
        if (!$this->journal->claim($effectKey, $messageId, $now)) {
            return false;
        }
        try {
            $this->sender->send($digest, $messageId);
            $this->store->outcome($digest, 'accepted');
            $this->journal->accept($effectKey, $now);
        } catch (\Throwable) {
            $this->store->outcome($digest, 'unknown');
            $this->journal->uncertain($effectKey, $now);
            throw new \App\Application\Operations\UncertainMailDelivery('Availability mail outcome is uncertain; reconcile the stable Message-ID before retrying.');
        }

        return true;
    }
}
