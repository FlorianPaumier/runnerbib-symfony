<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Application\Finance\SellerProvider;
use App\Domain\Finance\SellerIdentity;
use App\Domain\Finance\SellerReadiness;

final class ControlledSellerProvider implements SellerProvider
{
    /** @var \Closure(): void|null */
    public ?\Closure $onCreation = null;
    public string $configurationFingerprint = 'controlled-configuration-v1';
    public bool $failReadiness = false;
    public bool $failNextCreation = false;
    /** @var list<string> */
    public array $creationKeys = [];
    public ?string $submittedCountry = null;
    public ?string $submittedEmail = null;
    public int $accountsCreated = 0;
    public int $linksCreated = 0;
    public SellerReadiness $status = SellerReadiness::Pending;

    public function configurationFingerprint(): string
    {
        return $this->configurationFingerprint;
    }

    public function assertConfigured(): void
    {
    }

    public function createRecipient(SellerIdentity $identity, string $operationId, string $idempotencyKey): string
    {
        $this->submittedCountry = $identity->country;
        $this->submittedEmail = $identity->email;
        if (!in_array($idempotencyKey, $this->creationKeys, true)) {
            ++$this->accountsCreated;
        }
        $this->creationKeys[] = $idempotencyKey;
        if (null !== $this->onCreation) {
            ($this->onCreation)();
        }
        if ($this->failNextCreation) {
            $this->failNextCreation = false;
            throw new \RuntimeException('Controlled transport lost response.');
        }

        return 'acct_recipient';
    }

    public function readiness(string $providerReference): SellerReadiness
    {
        if ($this->failReadiness) {
            throw new \App\Application\Finance\SellerProviderUnavailable('Controlled readiness unavailable.');
        }

        return $this->status;
    }

    public function onboardingLink(string $providerReference): string
    {
        return 'https://connect.stripe.test/onboarding/'.++$this->linksCreated;
    }
}
