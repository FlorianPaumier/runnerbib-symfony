<?php

declare(strict_types=1);

namespace App\Infrastructure\Newsletter;

use App\Application\Newsletter\DeliverNewsletterWelcome;
use App\Application\Newsletter\NewsletterTokens;
use App\Application\Operations\OutboxEventHandler;
use App\Application\Operations\OutboxMessage;

final readonly class NewsletterMessageHandler implements OutboxEventHandler
{
    public function __construct(private DeliverNewsletterWelcome $delivery, private NewsletterTokens $tokens)
    {
    }

    public function supports(string $eventType): bool
    {
        return 'newsletter.subscription_registered' === $eventType;
    }

    public function __invoke(OutboxMessage $message): void
    {
        if (!$this->supports($message->eventType) || 1 !== $message->payloadVersion) {
            throw new \InvalidArgumentException('Invalid newsletter message.');
        }
        try {
            $payload = json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Invalid newsletter message.');
        }
        if (!is_array($payload) || !is_string($payload['unsubscribe_token'] ?? null)) {
            throw new \InvalidArgumentException('Invalid newsletter message.');
        }
        $authority = $this->tokens->verify($payload['unsubscribe_token']);
        if ($authority->subscriptionId !== $message->aggregateId) {
            throw new \InvalidArgumentException('Invalid newsletter message.');
        }
        $this->delivery->deliver($message->effectKey, $payload['unsubscribe_token'], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }
}
