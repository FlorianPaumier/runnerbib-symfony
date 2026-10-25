<?php

declare(strict_types=1);

namespace App\Infrastructure\Newsletter;

use App\Application\Newsletter\NewsletterStore;
use App\Application\Newsletter\NewsletterSubscription;
use Doctrine\DBAL\Connection;

final readonly class DbalNewsletterStore implements NewsletterStore
{
    public function __construct(private Connection $database)
    {
    }

    public function current(\App\Application\Newsletter\NewsletterUnsubscribeAuthority $authority): ?NewsletterSubscription
    {
        $row = $this->database->fetchAssociative('SELECT id::text,email,join_at::text FROM newsletter_subscriptions WHERE id=? AND subscribed AND join_at=?', [$authority->subscriptionId, $authority->joinedAt->format('Y-m-d H:i:s.uP')]);
        if (false === $row) {
            return null;
        }

        return $this->subscription($row);
    }

    public function unsubscribe(\App\Application\Newsletter\NewsletterUnsubscribeAuthority $authority, ?string $reason, \DateTimeImmutable $now): bool
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Newsletter mutation requires an owning transaction.');
        }

        return 1 === $this->database->executeStatement('UPDATE newsletter_subscriptions SET subscribed=false,quit_at=GREATEST(?::timestamptz,join_at),quit_reason=? WHERE id=? AND subscribed AND join_at=?', [$now->format('Y-m-d H:i:s.uP'), $reason, $authority->subscriptionId, $authority->joinedAt->format('Y-m-d H:i:s.uP')]);
    }

    public function subscribe(string $requestId, string $normalizedEmail, \DateTimeImmutable $now): ?NewsletterSubscription
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Newsletter mutation requires an owning transaction.');
        }
        $row = $this->database->fetchAssociative("INSERT INTO newsletter_subscriptions(id,email,email_normalized,subscribed,join_at) VALUES(?,?,?,true,?) ON CONFLICT(email_normalized) DO UPDATE SET email=EXCLUDED.email,subscribed=true,join_at=GREATEST(EXCLUDED.join_at,newsletter_subscriptions.join_at+interval '1 microsecond'),quit_at=NULL,quit_reason=NULL WHERE NOT newsletter_subscriptions.subscribed RETURNING id::text,email,join_at::text", [$requestId, $normalizedEmail, $normalizedEmail, $now->format('Y-m-d H:i:s.uP')]);
        if (false === $row) {
            return null;
        }

        return $this->subscription($row);
    }

    /** @param array<string,mixed> $row */
    private function subscription(array $row): NewsletterSubscription
    {
        if (!is_string($row['id']) || !is_string($row['email']) || !is_string($row['join_at'])) {
            throw new \UnexpectedValueException('Invalid stored newsletter subscription.');
        }

        return new NewsletterSubscription($row['id'], $row['email'], new \DateTimeImmutable($row['join_at']));
    }
}
