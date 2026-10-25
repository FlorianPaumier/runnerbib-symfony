<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\ReconcileCheckout;
use App\Application\Operations\OutboxEventHandler;
use App\Application\Operations\OutboxMessage;
use Psr\Clock\ClockInterface;

final readonly class CheckoutCancellationMessageHandler implements OutboxEventHandler
{
    public function __construct(private ReconcileCheckout $reconciliation, private ClockInterface $clock)
    {
    }

    public function supports(string $eventType): bool
    {
        return 'checkout.cancellation_requested' === $eventType;
    }

    public function __invoke(OutboxMessage $message): void
    {
        if (!$this->supports($message->eventType) || 1 !== $message->payloadVersion || 'checkout' !== $message->aggregateKind || $message->aggregateVersion < 1 || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $message->aggregateId)) {
            throw new \InvalidArgumentException('Invalid checkout cancellation request.');
        }
        try {
            $payload = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Invalid checkout cancellation request.');
        }
        if (!is_array($payload) || array_keys($payload) !== ['operation_id'] || !is_string($payload['operation_id']) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $payload['operation_id'])) {
            throw new \InvalidArgumentException('Invalid checkout cancellation request.');
        }
        $this->reconciliation->reconcile($message->aggregateId, $this->clock->now(), 'cancellation:'.bin2hex(random_bytes(16)), $payload['operation_id']);
    }
}
