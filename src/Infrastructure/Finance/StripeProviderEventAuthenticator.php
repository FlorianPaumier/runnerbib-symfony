<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\AuthenticatedProviderEvent;
use App\Application\Finance\ProviderEventAuthenticator;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;

final readonly class StripeProviderEventAuthenticator implements ProviderEventAuthenticator
{
    public function __construct(private string $webhookSecret, private string $providerScope, private bool $liveMode)
    {
    }

    public function authenticate(string $rawBody, string $signature, \DateTimeImmutable $now): AuthenticatedProviderEvent
    {
        if (!str_starts_with($this->webhookSecret, 'whsec_') || 'whsec_' === $this->webhookSecret || '' === trim($this->providerScope) || 'unconfigured' === $this->providerScope) {
            throw new \RuntimeException('Webhook operator configuration is required.');
        }
        if (1 !== preg_match('/\A[^,=]+=[^,]*(?:,[^,=]+=[^,]*)*\z/D', $signature) || 1 !== preg_match_all('/(?:\A|,)t=([0-9]{1,12})(?=,|\z)/D', $signature, $timestamps) || abs($now->getTimestamp() - (int) $timestamps[1][0]) > 300) {
            throw new \InvalidArgumentException('Provider event signature format or timestamp is invalid.');
        }
        // The SDK uses global time; the same 300-second bound is checked above against trusted runtime time.
        try {
            WebhookSignature::verifyHeader($rawBody, $signature, $this->webhookSecret, 0);
        } catch (SignatureVerificationException $exception) {
            throw new \InvalidArgumentException('Provider event authentication failed.', previous: $exception);
        }
        try {
            $event = json_decode($rawBody, false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Invalid provider event envelope.', previous: $exception);
        }
        if (!$event instanceof \stdClass || !is_string($event->id ?? null) || '' === $event->id || !is_string($event->type ?? null) || '' === $event->type || !is_string($event->api_version ?? null) || !is_int($event->created ?? null) || !is_bool($event->livemode ?? null) || !($event->data ?? null) instanceof \stdClass || !($event->data->object ?? null) instanceof \stdClass || !is_string($event->data->object->id ?? null) || !is_string($event->data->object->object ?? null)) {
            throw new \InvalidArgumentException('Invalid provider event envelope.');
        }
        if ($event->livemode !== $this->liveMode) {
            throw new \InvalidArgumentException('Provider event mode does not match this inbox.');
        }
        $payload = ['id' => $event->id, 'type' => $event->type, 'api_version' => $event->api_version, 'created' => $event->created, 'livemode' => $event->livemode, 'data' => ['object' => $this->project($event->data->object)]];
        if (property_exists($event, 'account')) {
            if (!is_string($event->account) && null !== $event->account) {
                throw new \InvalidArgumentException('Invalid provider account.');
            }
            $payload['account'] = $event->account;
        }

        return new AuthenticatedProviderEvent($this->providerScope, $event->id, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function project(\stdClass $object): \stdClass
    {
        $projection = new \stdClass();
        foreach (['id', 'object', 'status', 'payment_status', 'amount_total', 'amount', 'amount_received', 'currency', 'client_reference_id', 'created', 'livemode', 'canceled_at'] as $field) {
            if (property_exists($object, $field) && (is_scalar($object->{$field}) || null === $object->{$field})) {
                $projection->{$field} = $object->{$field};
            }
        }
        foreach (['payment_intent', 'latest_charge'] as $reference) {
            if (property_exists($object, $reference)) {
                $value = $object->{$reference};
                if (is_string($value) || null === $value) {
                    $projection->{$reference} = $value;
                } elseif ($value instanceof \stdClass) {
                    $projection->{$reference} = $this->project($value);
                }
            }
        }
        if (($object->metadata ?? null) instanceof \stdClass) {
            $projection->metadata = new \stdClass();
            foreach (['checkout_id', 'operation_id'] as $field) {
                if (is_string($object->metadata->{$field} ?? null)) {
                    $projection->metadata->{$field} = $object->metadata->{$field};
                }
            }
        }

        return $projection;
    }
}
