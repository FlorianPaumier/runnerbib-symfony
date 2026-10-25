<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\AuthenticatedProviderEvent;
use App\Application\Finance\ProviderEvent;
use App\Application\Finance\ProviderEventInbox;
use Doctrine\DBAL\Connection;

final readonly class DbalProviderEventInbox implements ProviderEventInbox
{
    public function __construct(private Connection $database)
    {
    }

    public function persist(AuthenticatedProviderEvent $event, \DateTimeImmutable $now): bool
    {
        if ($this->database->isTransactionActive()) {
            throw new \LogicException('Provider inbox requires an independent committed write.');
        }
        try {
            return 1 === $this->database->executeStatement("INSERT INTO provider_event_inbox(id,provider,provider_scope,event_ref,received_at,authenticated_at,payload_version,protected_payload,processing_state,next_attempt_at) VALUES(gen_random_uuid(),'stripe',?,?,?,?,1,?,'pending',?) ON CONFLICT(provider,provider_scope,event_ref) DO NOTHING", [$event->providerScope, $event->eventReference, $now->format(DATE_ATOM), $now->format(DATE_ATOM), $event->protectedPayload, $now->format(DATE_ATOM)]);
        } catch (\Doctrine\DBAL\Exception $exception) {
            throw new \RuntimeException('Authenticated provider event could not be committed.', previous: $exception);
        }
    }

    public function claimDue(\DateTimeImmutable $now, string $worker, int $limit): array
    {
        $rows = $this->database->fetchAllAssociative(
            "UPDATE provider_event_inbox SET processing_state='processing',claimed_by=?,lease_until=?,fencing_generation=fencing_generation+1 WHERE id IN (SELECT id FROM provider_event_inbox WHERE ((processing_state IN ('pending','failed') AND (next_attempt_at IS NULL OR next_attempt_at<=?)) OR (processing_state='processing' AND lease_until<=?)) ORDER BY received_at,id LIMIT ? FOR UPDATE SKIP LOCKED) RETURNING id::text,fencing_generation,event_ref,received_at::text,protected_payload->>'type' AS event_type,protected_payload->'data'->'object'->'metadata'->>'checkout_id' AS checkout_id",
            [$worker, $now->modify('+60 seconds')->format('Y-m-d H:i:s.uP'), $now->format('Y-m-d H:i:s.uP'), $now->format('Y-m-d H:i:s.uP'), $limit]
        );
        $events = [];
        foreach ($rows as $row) {
            $eventReference = $row['event_ref'] ?? null;
            $receivedAt = $row['received_at'] ?? null;
            $eventType = $row['event_type'] ?? null;
            assert(is_string($eventReference));
            assert(is_string($receivedAt));
            assert(is_string($eventType));
            $checkoutId = $row['checkout_id'] ?? null;
            assert(null === $checkoutId || is_string($checkoutId));
            $id = $row['id'];
            $generation = $row['fencing_generation'];
            assert(is_string($id));
            assert(is_int($generation));
            $events[] = new ProviderEvent($eventReference, new \DateTimeImmutable($receivedAt), $eventType, $checkoutId, $id, $generation);
        }

        return $events;
    }

    public function complete(ProviderEvent $event, string $worker, \DateTimeImmutable $now): bool
    {
        return 1 === $this->database->executeStatement("UPDATE provider_event_inbox SET processing_state='completed',processed_at=?,claimed_by=NULL,lease_until=NULL WHERE id=? AND fencing_generation=? AND processing_state='processing' AND claimed_by=?", [$now->format(DATE_ATOM), $event->id, $event->generation, $worker]);
    }

    public function retry(ProviderEvent $event, string $worker, \DateTimeImmutable $now, \DateTimeImmutable $nextAttempt): bool
    {
        return 1 === $this->database->executeStatement("UPDATE provider_event_inbox SET processing_state='failed',next_attempt_at=?,claimed_by=NULL,lease_until=NULL WHERE id=? AND fencing_generation=? AND processing_state='processing' AND claimed_by=?", [$nextAttempt->format('Y-m-d H:i:s.uP'), $event->id, $event->generation, $worker]);
    }
}
