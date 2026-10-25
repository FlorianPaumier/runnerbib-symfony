<?php

declare(strict_types=1);

namespace App\Tests\Integration\Operations;

use App\Application\Operations\PurgeExpiredRecords;
use App\Infrastructure\Operations\DbalRetentionStore;
use App\Tests\Support\IsolatedWebTestCase;

final class RetentionPurgeTest extends IsolatedWebTestCase
{
    public function testExpiredRecordsAreDeletedAndRecentOrReferencedRecordsAreKept(): void
    {
        $this->database->insert('accounts', ['id' => '00000000-0000-0000-0000-000000000001', 'roles' => '["runner"]', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-01-01Z']);
        $this->database->insert('account_credentials', ['id' => '00000000-0000-0000-0000-000000000002', 'account_id' => '00000000-0000-0000-0000-000000000001', 'purpose' => 'verification', 'token_hash' => 'expired-hash', 'created_at' => '2026-01-01Z', 'expires_at' => '2026-01-02Z', 'consumed_at' => '2026-01-01T12:00:00Z']);
        $this->database->insert('account_credentials', ['id' => '00000000-0000-0000-0000-000000000003', 'account_id' => '00000000-0000-0000-0000-000000000001', 'purpose' => 'verification', 'token_hash' => 'recent-hash', 'created_at' => '2026-10-08Z', 'expires_at' => '2026-10-09Z', 'consumed_at' => '2026-10-08T12:00:00Z']);
        $this->database->insert('consumed_effects', ['consumer' => 'account-handler', 'effect_key' => 'expired-effect', 'completed_at' => '2026-01-01Z']);
        $this->database->insert('consumed_effects', ['consumer' => 'account-handler', 'effect_key' => 'recent-effect', 'completed_at' => '2026-10-01Z']);
        $this->database->insert('outbox_messages', ['id' => '00000000-0000-0000-0000-000000000004', 'effect_key' => 'expired-intent', 'aggregate_kind' => 'fixture', 'aggregate_id' => '00000000-0000-0000-0000-000000000001', 'aggregate_version' => 1, 'event_type' => 'fixture.test', 'payload_version' => 1, 'payload' => '{}', 'created_at' => '2026-01-01Z', 'dispatched_at' => '2026-01-01T00:05:00Z']);
        $this->database->insert('outbox_messages', ['id' => '00000000-0000-0000-0000-000000000005', 'effect_key' => 'recent-intent', 'aggregate_kind' => 'fixture', 'aggregate_id' => '00000000-0000-0000-0000-000000000001', 'aggregate_version' => 1, 'event_type' => 'fixture.test', 'payload_version' => 1, 'payload' => '{}', 'created_at' => '2026-10-01Z', 'dispatched_at' => '2026-10-01T00:05:00Z']);
        $this->database->insert('outbox_messages', ['id' => '00000000-0000-0000-0000-000000000006', 'effect_key' => 'undispatched-intent', 'aggregate_kind' => 'fixture', 'aggregate_id' => '00000000-0000-0000-0000-000000000001', 'aggregate_version' => 1, 'event_type' => 'fixture.test', 'payload_version' => 1, 'payload' => '{}', 'created_at' => '2026-01-01Z']);
        $this->database->insert('scheduled_runs', ['id' => '00000000-0000-0000-0000-000000000007', 'job_name' => 'availability_notifications', 'local_run_date' => '2026-01-01', 'timezone' => 'UTC', 'scheduled_at' => '2026-01-01Z', 'state' => 'completed', 'completed_at' => '2026-01-01T00:05:00Z']);
        $this->database->insert('provider_event_inbox', ['id' => '00000000-0000-0000-0000-000000000008', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'event_ref' => 'evt_expired', 'received_at' => '2026-01-01Z', 'authenticated_at' => '2026-01-01Z', 'payload_version' => 1, 'protected_payload' => '{}', 'processing_state' => 'pending']);
        $this->database->insert('provider_event_inbox', ['id' => '00000000-0000-0000-0000-000000000009', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'event_ref' => 'evt_kept', 'received_at' => '2026-01-01Z', 'authenticated_at' => '2026-01-01Z', 'payload_version' => 1, 'protected_payload' => '{}', 'processing_state' => 'pending']);
        $this->database->insert('checkouts', ['id' => '00000000-0000-0000-0000-00000000000b', 'buyer_id' => '00000000-0000-0000-0000-000000000001', 'state' => 'pending', 'authorized_at' => '2026-01-01T00:00:00Z', 'initial_deadline' => '2026-01-01T00:15:00Z', 'expected_total_cents' => 100, 'currency' => 'EUR']);
        $this->database->insert('financial_operations', ['id' => '00000000-0000-0000-0000-00000000000c', 'semantic_key' => 'checkout:b', 'kind' => 'checkout_payment', 'checkout_id' => '00000000-0000-0000-0000-00000000000b', 'expected_amount_cents' => 100, 'currency' => 'EUR', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'parameters' => '{}', 'parameters_hash' => 'hash', 'created_at' => '2026-01-01Z']);
        $this->database->insert('payment_attempts', ['id' => '00000000-0000-0000-0000-00000000000a', 'checkout_id' => '00000000-0000-0000-0000-00000000000b', 'attempt_no' => 1, 'expected_amount_cents' => 100, 'currency' => 'EUR', 'state' => 'prepared', 'operation_id' => '00000000-0000-0000-0000-00000000000c', 'provider' => 'stripe', 'provider_scope' => 'test:platform', 'validation_event_id' => '00000000-0000-0000-0000-000000000009']);

        $counts = (new PurgeExpiredRecords(new DbalRetentionStore($this->database)))->purge(new \DateTimeImmutable('2026-10-10T00:00:00Z'));

        self::assertSame(1, $counts['outbox_messages']);
        self::assertSame(1, $counts['account_credentials']);
        self::assertSame(1, $counts['consumed_effects']);
        self::assertSame(1, $counts['scheduled_runs']);
        self::assertSame(1, $counts['provider_event_inbox']);
        self::assertSame('recent-intent', $this->database->fetchOne('SELECT effect_key FROM outbox_messages WHERE dispatched_at IS NOT NULL'));
        self::assertSame('undispatched-intent', $this->database->fetchOne('SELECT effect_key FROM outbox_messages WHERE dispatched_at IS NULL'));
        self::assertSame('recent-hash', $this->database->fetchOne('SELECT token_hash FROM account_credentials'));
        self::assertSame('recent-effect', $this->database->fetchOne('SELECT effect_key FROM consumed_effects'));
        self::assertSame('evt_kept', $this->database->fetchOne('SELECT event_ref FROM provider_event_inbox'));
    }

    public function testPendingNotificationsAreNeverPurged(): void
    {
        $this->database->insert('accounts', ['id' => '00000000-0000-0000-0000-000000000001', 'roles' => '["runner"]', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-01-01Z']);
        $this->database->insert('notifications', ['id' => '00000000-0000-0000-0000-00000000000d', 'recipient_id' => '00000000-0000-0000-0000-000000000001', 'event_key' => 'availability:old-pending', 'category' => 'availability', 'payload_version' => 1, 'private_payload' => '{}', 'deadline' => '2026-01-01Z', 'created_at' => '2026-01-01Z', 'delivery_state' => '{"email":"pending","in_app":"pending"}']);
        $this->database->insert('notifications', ['id' => '00000000-0000-0000-0000-00000000000e', 'recipient_id' => '00000000-0000-0000-0000-000000000001', 'event_key' => 'availability:old-skipped', 'category' => 'availability', 'payload_version' => 1, 'private_payload' => '{}', 'deadline' => '2026-01-01Z', 'created_at' => '2026-01-01Z', 'delivery_state' => '{"email":"skipped","in_app":"skipped"}']);

        $counts = (new PurgeExpiredRecords(new DbalRetentionStore($this->database)))->purge(new \DateTimeImmutable('2026-10-10T00:00:00Z'));

        self::assertSame(1, $counts['notifications']);
        self::assertSame('availability:old-pending', $this->database->fetchOne('SELECT event_key FROM notifications'));
    }
}
