<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\CredentialDeliveryStore;
use App\Application\Operations\UncertainMailDelivery;
use Doctrine\DBAL\Connection;

final readonly class DbalCredentialDeliveryStore implements CredentialDeliveryStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function claim(string $effectKey, string $messageId, \DateTimeImmutable $now): bool
    {
        $result = $this->connection->transactional(function () use ($effectKey, $messageId, $now): ?bool {
            $state = $this->connection->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages WHERE effect_key=? FOR UPDATE", [$effectKey]);
            if (false === $state) {
                throw new \LogicException('Credential mail intent is missing');
            }
            if (null === $state) {
                $this->connection->executeStatement("UPDATE outbox_messages SET delivery_state=jsonb_set(delivery_state,'{smtp}',jsonb_build_object('state','sending','message_id',?::text,'attempted_at',?::text)) WHERE effect_key=?", [$messageId, $now->format(DATE_ATOM), $effectKey]);

                return true;
            }
            if (\in_array($state, ['accepted', 'expired'], true)) {
                return false;
            }
            $this->uncertain($effectKey, $now);

            return null;
        });
        if (null === $result) {
            throw new UncertainMailDelivery('Credential mail outcome is uncertain; reconcile the stable Message-ID before retrying.');
        }

        return $result;
    }

    public function accept(string $effectKey, \DateTimeImmutable $now): void
    {
        $this->setState($effectKey, 'accepted', $now);
        // The protected token is no longer needed once the mail left the system.
        $this->connection->executeStatement("UPDATE outbox_messages SET payload=payload - 'token' WHERE effect_key=? AND jsonb_exists(payload,'token')", [$effectKey]);
    }

    public function uncertain(string $effectKey, \DateTimeImmutable $now): void
    {
        $this->setState($effectKey, 'unknown', $now);
    }

    public function expire(string $effectKey, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("UPDATE outbox_messages SET delivery_state=jsonb_set(delivery_state,'{smtp}',jsonb_build_object('state','expired','observed_at',?::text)) WHERE effect_key=? AND delivery_state->'smtp' IS NULL", [$now->format(DATE_ATOM), $effectKey]);
    }

    private function setState(string $effectKey, string $state, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("UPDATE outbox_messages SET delivery_state=jsonb_set(delivery_state,'{smtp}',(delivery_state->'smtp')||jsonb_build_object('state',?::text,'observed_at',?::text)) WHERE effect_key=? AND delivery_state->'smtp'->>'state' IN ('sending','unknown')", [$state, $now->format(DATE_ATOM), $effectKey]);
    }
}
