<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\CredentialMail;
use App\Application\Operations\CredentialTokenProtector;
use App\Application\Operations\DeliverCredentialMail;
use App\Application\Operations\OutboxEventHandler;
use App\Application\Operations\OutboxMessage;

final readonly class CredentialMailMessageHandler implements OutboxEventHandler
{
    public function __construct(private DeliverCredentialMail $delivery, private CredentialTokenProtector $protector)
    {
    }

    public function supports(string $eventType): bool
    {
        return 'identity.credential_mail' === $eventType;
    }

    public function __invoke(OutboxMessage $message): void
    {
        if (!$this->supports($message->eventType) || 1 !== $message->payloadVersion) {
            throw new \InvalidArgumentException('Unsupported or invalid credential mail message');
        }
        try {
            $payload = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Unsupported or invalid credential mail message');
        }
        if (!\is_array($payload) || !\is_string($payload['email'] ?? null) || !\is_string($payload['purpose'] ?? null) || !\is_string($payload['token'] ?? null) || !\is_string($payload['expires_at'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported or invalid credential mail message');
        }
        if (false === filter_var($payload['email'], FILTER_VALIDATE_EMAIL) || !\in_array($payload['purpose'], ['verification', 'password_reset'], true) || '' === $payload['token']) {
            throw new \InvalidArgumentException('Unsupported or invalid credential mail message');
        }
        $expiry = \DateTimeImmutable::createFromFormat(DATE_ATOM, $payload['expires_at']);
        if (false === $expiry || $expiry->format(DATE_ATOM) !== $payload['expires_at']) {
            throw new \InvalidArgumentException('Unsupported or invalid credential mail message');
        }
        try {
            $token = $this->protector->reveal($payload['token']);
        } catch (\RuntimeException) {
            throw new \InvalidArgumentException('Unsupported or invalid credential mail message');
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Unsupported or invalid credential mail message');
        }
        $this->delivery->deliver($message->effectKey, new CredentialMail($payload['email'], $payload['purpose'], $token, $expiry), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }
}
