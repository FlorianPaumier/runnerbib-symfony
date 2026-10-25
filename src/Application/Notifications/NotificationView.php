<?php

declare(strict_types=1);

namespace App\Application\Notifications;

final readonly class NotificationView
{
    /** @param array<string,string> $deliveryState */
    public function __construct(public string $id, public string $category, public string $privatePayload, public \DateTimeImmutable $createdAt, public ?\DateTimeImmutable $readAt, public array $deliveryState, public int $payloadVersion = 1)
    {
    }

    /** @return array{listingId: string, priceCents: int, priorityEndsAt: \DateTimeImmutable}|null */
    public function availability(): ?array
    {
        if ('availability' !== $this->category || 1 !== $this->payloadVersion) {
            return null;
        }
        try {
            $payload = json_decode($this->privatePayload, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload) || 'EUR' !== ($payload['currency'] ?? null) || !is_string($payload['listing_id'] ?? null) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $payload['listing_id']) || !is_int($payload['price_cents'] ?? null) || $payload['price_cents'] <= 0 || !is_string($payload['priority_ends_at'] ?? null) || 1 !== preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/D', $payload['priority_ends_at'])) {
                return null;
            }

            $deadline = new \DateTimeImmutable($payload['priority_ends_at']);
            if (false !== \DateTimeImmutable::getLastErrors()) {
                return null;
            }

            return ['listingId' => $payload['listing_id'], 'priceCents' => $payload['price_cents'], 'priorityEndsAt' => $deadline];
        } catch (\JsonException|\DateMalformedStringException) {
            return null;
        }
    }
}
