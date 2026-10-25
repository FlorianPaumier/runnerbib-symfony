<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Application\Operations\TransactionManager;

final readonly class ManageProfile
{
    public function __construct(private CurrentIdentity $identity, private ProfileStore $profiles, private IdentityEffects $effects, private TransactionManager $transactions, private AuditWriter $audit, private ?string $optionalEmailPolicyVersion = null, private ?string $optionalInAppPolicyVersion = null)
    {
    }

    public function current(): ?AccountProfile
    {
        $accountId = $this->identity->accountId();

        return null === $accountId ? null : $this->profiles->privateProfile($accountId);
    }

    public function publicProfile(string $accountId): ?PublicProfile
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $accountId)) {
            return null;
        }

        return $this->profiles->publicProfile($accountId);
    }

    public function update(?string $displayName, bool $optionalEmailEnabled, bool $optionalInAppEnabled, int $expectedVersion): bool
    {
        $accountId = $this->identity->accountId();
        if (null === $accountId) {
            return false;
        }
        $name = null === $displayName || '' === trim($displayName) ? null : trim($displayName);
        $updated = false;
        $this->transactions->run(function () use ($accountId, $name, $optionalEmailEnabled, $optionalInAppEnabled, $expectedVersion, &$updated): void {
            $current = $this->profiles->privateProfile($accountId);
            if (null === $current || $current->version !== $expectedVersion) {
                return;
            }
            if ((!$current->optionalEmailEnabled && $optionalEmailEnabled && '' === trim($this->optionalEmailPolicyVersion ?? '')) || (!$current->optionalInAppEnabled && $optionalInAppEnabled && '' === trim($this->optionalInAppPolicyVersion ?? ''))) {
                throw new \DomainException('Optional notification policy is not configured.');
            }
            $updated = $this->profiles->update($accountId, $name, $optionalEmailEnabled, $optionalInAppEnabled, $expectedVersion);
            if ($updated) {
                foreach ([
                    ['optional_notification_email', $current->optionalEmailEnabled, $optionalEmailEnabled, $this->optionalEmailPolicyVersion],
                    ['optional_notification_in_app', $current->optionalInAppEnabled, $optionalInAppEnabled, $this->optionalInAppPolicyVersion],
                ] as [$purpose, $previous, $enabled, $policyVersion]) {
                    if ($previous === $enabled) {
                        continue;
                    }
                    $this->audit->append(new AuditEntry($this->effects->newId(), $accountId, null, json_encode(['scope' => 'self_service', 'source' => 'profile', 'purpose' => $purpose, 'policy_version' => null === $policyVersion || '' === trim($policyVersion) ? null : trim($policyVersion), 'previous_enabled' => $previous, 'enabled' => $enabled, 'account_version' => $expectedVersion + 1], JSON_THROW_ON_ERROR), $enabled ? 'notification.preference_granted' : 'notification.preference_withdrawn', 'account', $accountId, 'Optional notification preference changed', $this->effects->now(), 'completed'));
                }
                $this->audit->append(new AuditEntry($this->effects->newId(), $accountId, null, json_encode(['scope' => 'self_service', 'optional_email_enabled' => $optionalEmailEnabled, 'optional_in_app_enabled' => $optionalInAppEnabled], JSON_THROW_ON_ERROR), 'account.profile_updated', 'account', $accountId, 'Private profile preferences updated', $this->effects->now(), 'completed'));
            }
        });

        return $updated;
    }
}
