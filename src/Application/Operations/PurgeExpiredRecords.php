<?php

declare(strict_types=1);

namespace App\Application\Operations;

final readonly class PurgeExpiredRecords
{
    private const OUTBOX_DAYS = 30;
    private const CREDENTIAL_DAYS = 7;
    private const EFFECT_DAYS = 30;
    private const NOTIFICATION_DAYS = 90;
    private const RUN_DAYS = 30;
    private const PROVIDER_EVENT_DAYS = 90;

    public function __construct(private RetentionStore $store)
    {
    }

    /**
     * @return array<string,int> deleted rows per table
     */
    public function purge(\DateTimeImmutable $now): array
    {
        return $this->store->purge([
            'outbox_messages' => $now->sub(new \DateInterval('P'.self::OUTBOX_DAYS.'D')),
            'account_credentials' => $now->sub(new \DateInterval('P'.self::CREDENTIAL_DAYS.'D')),
            'consumed_effects' => $now->sub(new \DateInterval('P'.self::EFFECT_DAYS.'D')),
            'notifications' => $now->sub(new \DateInterval('P'.self::NOTIFICATION_DAYS.'D')),
            'scheduled_runs' => $now->sub(new \DateInterval('P'.self::RUN_DAYS.'D')),
            'provider_event_inbox' => $now->sub(new \DateInterval('P'.self::PROVIDER_EVENT_DAYS.'D')),
        ]);
    }
}
