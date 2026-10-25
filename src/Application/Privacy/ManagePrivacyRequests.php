<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Application\Finance\CheckoutCancellationRequests;
use App\Application\Identity\CurrentIdentity;
use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Application\Operations\OutboxMessage;
use App\Application\Operations\OutboxWriter;
use App\Application\Operations\TransactionManager;
use App\Application\Selling\ListingStore;

final readonly class ManagePrivacyRequests
{
    public function __construct(private CurrentIdentity $identity, private PrivacyRequestStore $store, private TransactionManager $transactions, private ListingStore $listings, private CheckoutCancellationRequests $cancellations, private AuditWriter $audit, private OutboxWriter $outbox, private PrivacyRequestTokens $tokens, private ?int $progressAuthorityLifetimeSeconds = null)
    {
    }

    public function request(string $requestId, string $kind, \DateTimeImmutable $now): ?PrivacyRequestReceipt
    {
        $accountId = $this->identity->accountId();
        if (null === $accountId) {
            return null;
        }
        if (null === $this->progressAuthorityLifetimeSeconds || $this->progressAuthorityLifetimeSeconds < 1) {
            throw new UnconfiguredPrivacyProgressPolicy('Privacy progress expiry policy is not configured.');
        }
        if (!in_array($kind, ['access_export', 'correction', 'deletion'], true) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $requestId)) {
            throw new \InvalidArgumentException('Privacy request is invalid.');
        }
        $record = null;
        $this->transactions->run(function () use ($accountId, $requestId, $kind, $now, &$record): void {
            $existing = $this->store->existing($requestId);
            $version = 0;
            if (null === $existing) {
                $version = $this->store->lock($accountId, 'deletion' === $kind);
                $existing = $this->store->existing($requestId);
            }
            if (null !== $existing) {
                if ($existing->accountId !== $accountId || $existing->kind !== $kind) {
                    throw new \DomainException('Privacy request is unavailable.');
                }
                $record = $existing;

                return;
            }
            $blockers = [];
            if ('deletion' === $kind) {
                foreach ($this->store->unsoldListings($accountId) as $listing) {
                    if (!$this->listings->withdraw($accountId, $listing['id'], $listing['version'], $now)) {
                        throw new \DomainException('Privacy listing withdrawal changed; retry the request.');
                    }
                }
                $this->cancellations->requestBuyer($accountId, $now);
                $blockers = $this->store->fence($accountId, $now);
                ++$version;
            }
            $state = [] === $blockers ? 'pending' : 'blocked';
            $expiresAt = $now->add(new \DateInterval('PT'.$this->progressAuthorityLifetimeSeconds.'S'));
            $record = new PrivacyRequestRecord($requestId, $accountId, $kind, $state, $version, $expiresAt, $blockers);
            $metadata = json_encode(['kind' => $kind, 'state' => $state, 'account_version' => $version, 'authority_expires_at' => $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.uP'), 'request_version' => 1, 'blockers' => $blockers], JSON_THROW_ON_ERROR);
            $this->audit->append(new AuditEntry($requestId, $accountId, null, $metadata, 'privacy.requested', 'privacy_request', $requestId, null, $now, $state));
            $this->outbox->append(new OutboxMessage($requestId, 'privacy:'.$requestId.':requested', 'privacy_request', $requestId, 1, 'privacy.requested', 1, json_encode(['account_id' => $accountId, 'request_id' => $requestId, 'kind' => $kind], JSON_THROW_ON_ERROR), $now));
        });
        assert($record instanceof PrivacyRequestRecord);

        return new PrivacyRequestReceipt($record, $this->tokens->issue($record->requestId, $record->accountId, $record->accountVersion, $record->authorityExpiresAt));
    }

    public function status(string $token, \DateTimeImmutable $now): PrivacyRequestRecord
    {
        $record = $this->store->current($this->tokens->verify($token, $now));
        if (null === $record) {
            throw new \DomainException('Privacy request is unavailable.');
        }

        return $record;
    }
}
