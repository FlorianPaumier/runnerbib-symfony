<?php

declare(strict_types=1);

namespace App\Application\Newsletter;

use App\Application\Identity\IdentityEffects;
use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Application\Operations\OutboxMessage;
use App\Application\Operations\OutboxWriter;
use App\Application\Operations\TransactionManager;

final readonly class ManageNewsletter
{
    public function __construct(private NewsletterStore $store, private NewsletterTokens $tokens, private OutboxWriter $outbox, private TransactionManager $transactions, private AuditWriter $audit, private IdentityEffects $effects, private ?string $newsletterPolicyVersion = null)
    {
    }

    public function unsubscribe(string $token, ?string $reason, \DateTimeImmutable $now): bool
    {
        $authority = $this->tokens->verify($token);
        $reason = null === $reason || '' === trim($reason) ? null : trim($reason);
        $changed = false;
        $this->transactions->run(function () use ($authority, $reason, $now, &$changed): void {
            $changed = $this->store->unsubscribe($authority, $reason, $now);
            if ($changed) {
                $this->audit->append(new AuditEntry($this->effects->newId(), null, 'public_newsletter', json_encode(['scope' => 'public_newsletter', 'source' => 'signed_unsubscribe', 'purpose' => 'newsletter_email', 'policy_version' => null === $this->newsletterPolicyVersion || '' === trim($this->newsletterPolicyVersion) ? null : trim($this->newsletterPolicyVersion), 'generation' => $authority->joinedAt->format('Y-m-d\\TH:i:s.uP'), 'previous_enabled' => true, 'enabled' => false], JSON_THROW_ON_ERROR), 'newsletter.preference_withdrawn', 'newsletter_subscription', $authority->subscriptionId, 'Newsletter preference withdrawn', $now, 'completed'));
            }
        });

        return $changed;
    }

    public function subscribe(string $requestId, string $email, \DateTimeImmutable $now): void
    {
        if ('' === trim($this->newsletterPolicyVersion ?? '')) {
            throw new UnconfiguredNewsletterPolicy('Newsletter policy is not configured.');
        }
        $email = strtolower(trim($email));
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse courriel invalide.');
        }
        $this->transactions->run(function () use ($requestId, $email, $now): void {
            $subscription = $this->store->subscribe($requestId, $email, $now);
            if (null === $subscription) {
                return;
            }
            $this->audit->append(new AuditEntry($requestId, null, 'public_newsletter', json_encode(['scope' => 'public_newsletter', 'source' => 'public_signup', 'purpose' => 'newsletter_email', 'policy_version' => trim($this->newsletterPolicyVersion ?? ''), 'generation' => $subscription->joinedAt->format('Y-m-d\\TH:i:s.uP'), 'previous_enabled' => false, 'enabled' => true], JSON_THROW_ON_ERROR), 'newsletter.preference_granted', 'newsletter_subscription', $subscription->id, 'Newsletter preference granted', $now, 'completed'));
            $token = $this->tokens->issue($subscription->id, $subscription->joinedAt);
            $key = 'newsletter:joined:'.$subscription->id.':'.$subscription->joinedAt->format('Y-m-d\\TH:i:s.uP');
            $this->outbox->append(new OutboxMessage($requestId, $key, 'newsletter_subscription', $subscription->id, 1, 'newsletter.subscription_registered', 1, json_encode(['unsubscribe_token' => $token], JSON_THROW_ON_ERROR), $now));
        });
    }
}
