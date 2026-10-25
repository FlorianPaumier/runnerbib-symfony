<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Availability\FindOfferAccess;
use App\Application\Availability\OfferAccessView;
use App\Application\Notifications\AvailabilityBatchStore;
use Doctrine\DBAL\Connection;

final readonly class DbalAvailabilityBatchStore implements AvailabilityBatchStore
{
    public function __construct(private Connection $database, private FindOfferAccess $access)
    {
    }

    public function runNext(\DateTimeImmutable $now): bool
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Availability batching requires an owning transaction.');
        }
        $instant = $now->format('Y-m-d H:i:s.uP');
        $runId = $this->database->fetchOne("SELECT id::text FROM scheduled_runs WHERE job_name='availability_notifications' AND state='pending' AND scheduled_at<=? ORDER BY scheduled_at,id LIMIT 1", [$instant]);
        if (false === $runId) {
            return false;
        }
        if (!is_string($runId)) {
            throw new \UnexpectedValueException('Invalid stored batch.');
        }
        // Match the scheduler's race-before-run order to avoid a lock inversion.
        $this->database->fetchFirstColumn("SELECT id FROM races WHERE id IN (SELECT e.race_id FROM notifications n JOIN availability_events e ON e.id=n.availability_event_id WHERE n.delivery_state->>'batch_id'=?) ORDER BY id FOR UPDATE", [$runId]);
        $claimed = $this->database->fetchOne("SELECT id::text FROM scheduled_runs WHERE id=? AND state='pending' FOR UPDATE SKIP LOCKED", [$runId]);
        if (false === $claimed) {
            return false;
        }
        $rows = $this->database->fetchAllAssociative("SELECT n.id::text,n.recipient_id::text,n.availability_event_id::text,n.delivery_state::text FROM notifications n WHERE delivery_state->>'batch_id'=? AND delivery_state->>'batch_processed' IS DISTINCT FROM 'yes' ORDER BY recipient_id,id LIMIT 100 FOR UPDATE", [$runId]);
        $recipientsByEvent = [];
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['recipient_id']) || !is_string($row['availability_event_id']) || !is_string($row['delivery_state'])) {
                throw new \UnexpectedValueException('Invalid stored notification.');
            }
            $recipientsByEvent[$row['availability_event_id']][$row['recipient_id']] = true;
        }
        $access = [];
        foreach ($recipientsByEvent as $eventId => $recipients) {
            $access[$eventId] = $this->access->forEventRecipients($eventId, array_keys($recipients), $now);
        }
        $memberships = $this->memberships($recipientsByEvent);
        $digests = [];
        $states = [];
        foreach ($rows as $row) {
            $eventId = $row['availability_event_id'];
            $recipientId = $row['recipient_id'];
            $offer = $access[$eventId][$recipientId] ?? null;
            $membership = $memberships[$eventId][$recipientId] ?? null;
            $valid = $offer instanceof OfferAccessView && $offer->canInitiatePurchase && $now < $offer->priorityEndsAt && null !== $membership;
            $email = $valid && true === $membership['email_enabled'];
            $inApp = $valid && true === $membership['in_app_enabled'];
            $states[] = ['id' => $row['id'], 'state' => ['email' => $email ? 'pending' : 'skipped', 'in_app' => $inApp ? 'accepted' : 'skipped', 'batch_processed' => 'yes']];
            if ($email) {
                $digests[$recipientId][] = $row['id'];
            }
        }
        $this->database->executeStatement('UPDATE notifications n SET delivery_state=n.delivery_state || v.state FROM jsonb_to_recordset(CAST(:states AS jsonb)) AS v(id text,state jsonb) WHERE n.id::text=v.id', ['states' => json_encode($states, JSON_THROW_ON_ERROR)]);
        foreach ($digests as $recipient => $ids) {
            $this->database->executeStatement("INSERT INTO outbox_messages(id,effect_key,aggregate_kind,aggregate_id,aggregate_version,event_type,payload_version,payload,created_at) VALUES(gen_random_uuid(),?,'availability_batch',?,1,'notifications.availability_digest',1,?::jsonb,?)", ['availability-digest:'.$runId.':'.$recipient.':'.hash('sha256', implode(',', $ids)), $runId, json_encode(['recipient_id' => $recipient, 'notification_ids' => $ids], JSON_THROW_ON_ERROR), $instant]);
        }
        $remaining = $this->database->fetchOne("SELECT EXISTS(SELECT 1 FROM notifications WHERE delivery_state->>'batch_id'=? AND delivery_state->>'batch_processed' IS DISTINCT FROM 'yes')", [$runId]);
        if (false === $remaining) {
            $this->database->executeStatement("UPDATE scheduled_runs SET state='completed',started_at=?,completed_at=? WHERE id=?", [$instant, $instant, $runId]);
        }

        return true;
    }

    /**
     * Membership preferences of every recipient of the chunk, read in one query.
     *
     * @param array<string,array<string,true>> $recipientsByEvent
     *
     * @return array<string,array<string,array{email_enabled:bool,in_app_enabled:bool}>>
     */
    private function memberships(array $recipientsByEvent): array
    {
        $pairs = [];
        foreach ($recipientsByEvent as $eventId => $recipients) {
            foreach (array_keys($recipients) as $recipientId) {
                $pairs[$eventId.'|'.$recipientId] = ['event_id' => $eventId, 'account_id' => $recipientId];
            }
        }
        if ([] === $pairs) {
            return [];
        }
        $rows = $this->database->fetchAllAssociative("WITH chunk AS (SELECT event_id,account_id FROM jsonb_to_recordset(CAST(:pairs AS jsonb)) AS t(event_id uuid,account_id uuid)) SELECT c.event_id::text,c.account_id::text,m.email_enabled,m.in_app_enabled FROM chunk c JOIN availability_recipients ar ON ar.event_id=c.event_id AND ar.account_id=c.account_id JOIN race_waitlist_memberships m ON m.id=ar.membership_id JOIN accounts a ON a.id=ar.account_id WHERE m.ended_at IS NULL AND a.lifecycle_state='active' AND a.verified_at IS NOT NULL", ['pairs' => json_encode(array_values($pairs), JSON_THROW_ON_ERROR)]);
        $memberships = [];
        foreach ($rows as $row) {
            $eventId = $row['event_id'] ?? null;
            $accountId = $row['account_id'] ?? null;
            $emailEnabled = $row['email_enabled'] ?? null;
            $inAppEnabled = $row['in_app_enabled'] ?? null;
            assert(is_string($eventId));
            assert(is_string($accountId));
            assert(is_bool($emailEnabled));
            assert(is_bool($inAppEnabled));
            $memberships[$eventId][$accountId] = ['email_enabled' => $emailEnabled, 'in_app_enabled' => $inAppEnabled];
        }

        return $memberships;
    }

    public function lockOffer(string $eventId): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Availability batching requires an owning transaction.');
        }
        $raceId = $this->database->fetchOne('SELECT race_id::text FROM availability_events WHERE id=?', [$eventId]);
        if (false === $raceId) {
            return;
        }
        if (!is_string($raceId)) {
            throw new \UnexpectedValueException('Invalid stored race.');
        }
        $this->database->fetchOne('SELECT id FROM races WHERE id=? FOR UPDATE', [$raceId]);
    }

    public function schedule(OfferAccessView $offer, string $runId, \DateTimeImmutable $scheduledAt, \DateTimeImmutable $receivedAt): void
    {
        $this->database->executeStatement("INSERT INTO scheduled_runs(id,job_name,local_run_date,timezone,scheduled_at,state) VALUES (?,'availability_notifications',?,'UTC',?,'pending') ON CONFLICT(job_name,scheduled_at) DO NOTHING", [$runId, $scheduledAt->format('Y-m-d'), $scheduledAt->format('Y-m-d\\TH:i:s.uP')]);
        $run = $this->database->fetchAssociative("SELECT id::text,state FROM scheduled_runs WHERE job_name='availability_notifications' AND scheduled_at=? FOR UPDATE", [$scheduledAt->format('Y-m-d\\TH:i:s.uP')]);
        if (false === $run || !is_string($run['id'])) {
            throw new \UnexpectedValueException('Invalid stored batch.');
        }
        if ('pending' !== $run['state']) {
            throw new \DomainException('Availability batch is already closed.');
        }
        $payload = json_encode(['event_id' => $offer->eventId, 'listing_id' => $offer->listingId, 'race_id' => $offer->raceId, 'offer_version' => $offer->offerVersion, 'price_cents' => $offer->priceCents, 'currency' => $offer->currency, 'available_at' => $offer->availableAt->format('Y-m-d\\TH:i:s.uP'), 'priority_ends_at' => $offer->priorityEndsAt->format('Y-m-d\\TH:i:s.uP')], JSON_THROW_ON_ERROR);
        $this->database->executeStatement("INSERT INTO notifications(id,recipient_id,event_key,category,payload_version,private_payload,availability_event_id,deadline,created_at,delivery_state)
        SELECT gen_random_uuid(),ar.account_id,?,'availability',1,?::jsonb,?,?::timestamptz,?::timestamptz,
        jsonb_build_object('email',CASE WHEN m.email_enabled THEN 'pending' ELSE 'skipped' END,'in_app',CASE WHEN m.in_app_enabled THEN 'pending' ELSE 'skipped' END,'batch_id',?::text)
        FROM availability_recipients ar JOIN race_waitlist_memberships m ON m.id=ar.membership_id JOIN accounts a ON a.id=ar.account_id
        WHERE ar.event_id=? AND m.ended_at IS NULL AND a.lifecycle_state='active' AND a.verified_at IS NOT NULL AND (m.email_enabled OR m.in_app_enabled)
        ON CONFLICT(recipient_id,event_key) DO NOTHING", ['availability:'.$offer->eventId, $payload, $offer->eventId, $offer->priorityEndsAt->format('Y-m-d\\TH:i:s.uP'), $receivedAt->format('Y-m-d\\TH:i:s.uP'), $run['id'], $offer->eventId]);
    }
}
