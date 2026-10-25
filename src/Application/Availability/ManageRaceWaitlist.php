<?php

declare(strict_types=1);

namespace App\Application\Availability;

use App\Application\Identity\IdentityEffects;
use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Application\Operations\TransactionManager;
use App\Domain\Availability\WaitlistMembership;

final readonly class ManageRaceWaitlist
{
    public function __construct(private WaitlistStore $store, private TransactionManager $transactions, private AuditWriter $audit, private IdentityEffects $effects, private ?string $raceEmailPolicyVersion = null, private ?string $raceInAppPolicyVersion = null)
    {
    }

    public function join(string $accountId, string $raceId, string $membershipId, bool $emailEnabled, bool $inAppEnabled, \DateTimeImmutable $now): void
    {
        $this->transactions->run(function () use ($accountId, $raceId, $membershipId, $emailEnabled, $inAppEnabled, $now): void {
            $this->store->requireEligibleAccount($accountId);
            $this->store->lockPublishedRace($raceId);
            if (null === $this->store->active($accountId, $raceId)) {
                $this->requirePolicies($emailEnabled, $inAppEnabled);
                $this->store->add(new WaitlistMembership($membershipId, $raceId, $accountId, $emailEnabled, $inAppEnabled), $now);
                $this->evidence(new WaitlistMembership($membershipId, $raceId, $accountId, false, false), $emailEnabled, $inAppEnabled, $now);
            }
        });
    }

    public function leave(string $accountId, string $membershipId, \DateTimeImmutable $now): void
    {
        $this->transactions->run(function () use ($accountId, $membershipId, $now): void {
            $this->store->requireEligibleAccount($accountId);
            $membership = $this->store->owned($accountId, $membershipId);
            if (null === $membership) {
                throw new \DomainException('Membership is unavailable.');
            }
            $this->store->coordinateRace($membership->raceId);
            $current = $this->store->active($accountId, $membership->raceId);
            if (null === $current || $current->id !== $membershipId) {
                return;
            }
            if ($this->store->end($membershipId, $now)) {
                $this->evidence($current, false, false, $now);
            }
        });
    }

    public function preferences(string $accountId, string $membershipId, bool $emailEnabled, bool $inAppEnabled): void
    {
        $this->transactions->run(function () use ($accountId, $membershipId, $emailEnabled, $inAppEnabled): void {
            $this->store->requireEligibleAccount($accountId);
            $membership = $this->store->owned($accountId, $membershipId);
            if (null === $membership) {
                throw new \DomainException('Membership is unavailable.');
            }
            $this->store->coordinateRace($membership->raceId);
            $current = $this->store->active($accountId, $membership->raceId);
            if (null === $current || $current->id !== $membershipId) {
                throw new \DomainException('Membership is unavailable.');
            }
            $this->requirePolicies(!$current->emailEnabled && $emailEnabled, !$current->inAppEnabled && $inAppEnabled);
            $this->store->changePreferences($membershipId, $emailEnabled, $inAppEnabled);
            $this->evidence($current, $emailEnabled, $inAppEnabled, $this->effects->now());
        });
    }

    public function mine(string $accountId, string $raceId): ?WaitlistView
    {
        $this->store->requireEligibleAccount($accountId);

        $membership = $this->store->active($accountId, $raceId);

        return null === $membership ? null : new WaitlistView($membership->id, $membership->raceId, $membership->accountId, $membership->emailEnabled, $membership->inAppEnabled);
    }

    private function evidence(WaitlistMembership $previous, bool $emailEnabled, bool $inAppEnabled, \DateTimeImmutable $now): void
    {
        foreach ([
            ['race_notification_email', $previous->emailEnabled, $emailEnabled, $this->raceEmailPolicyVersion],
            ['race_notification_in_app', $previous->inAppEnabled, $inAppEnabled, $this->raceInAppPolicyVersion],
        ] as [$purpose, $before, $enabled, $policyVersion]) {
            if ($before === $enabled) {
                continue;
            }
            $this->audit->append(new AuditEntry($this->effects->newId(), $previous->accountId, null, json_encode(['scope' => 'self_service', 'source' => 'race_waitlist', 'race_id' => $previous->raceId, 'purpose' => $purpose, 'policy_version' => null === $policyVersion || '' === trim($policyVersion) ? null : trim($policyVersion), 'previous_enabled' => $before, 'enabled' => $enabled], JSON_THROW_ON_ERROR), $enabled ? 'race.notification_preference_granted' : 'race.notification_preference_withdrawn', 'race_waitlist_membership', $previous->id, 'Race notification preference changed', $now, 'completed'));
        }
    }

    private function requirePolicies(bool $emailGrant, bool $inAppGrant): void
    {
        if (($emailGrant && '' === trim($this->raceEmailPolicyVersion ?? '')) || ($inAppGrant && '' === trim($this->raceInAppPolicyVersion ?? ''))) {
            throw new UnconfiguredRaceNotificationPolicy('Race notification policy is not configured.');
        }
    }
}
