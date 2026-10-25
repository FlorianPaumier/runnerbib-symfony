<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\SubmitHostedCheckout;
use App\Application\Operations\OutboxEventHandler;
use App\Application\Operations\OutboxMessage;
use Psr\Clock\ClockInterface;

final readonly class HostedCheckoutMessageHandler implements OutboxEventHandler
{
    public function __construct(private SubmitHostedCheckout $submission, private ClockInterface $clock)
    {
    }

    public function supports(string $eventType): bool
    {
        return 'checkout.payment_prepared' === $eventType;
    }

    public function __invoke(OutboxMessage $message): void
    {
        if (!$this->supports($message->eventType) || 1 !== $message->payloadVersion || 'checkout' !== $message->aggregateKind || 1 !== $message->aggregateVersion || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $message->aggregateId)) {
            throw new \InvalidArgumentException('Invalid hosted-payment intent.');
        }
        try {
            $payload = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Invalid hosted-payment intent.');
        }
        if (!is_array($payload) || array_keys($payload) !== ['operation_id'] || !is_string($payload['operation_id']) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $payload['operation_id'])) {
            throw new \InvalidArgumentException('Invalid hosted-payment intent.');
        }
        $this->submission->submit($payload['operation_id'], $message->aggregateId, $this->clock->now(), 'payment:'.bin2hex(random_bytes(16)));
    }
}
