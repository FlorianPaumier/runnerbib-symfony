<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

final readonly class ClaimEligibility
{
    public function __construct(private string $accountState, private bool $verified, private string $participation, private string $source, private ?string $originalEmail, private string $accountEmail, private ?string $decision, private bool $currentReview, private bool $matchingSubmitter, private ?string $ownerAccountId, private bool $alreadyOwnsRace)
    {
    }

    public function allows(): bool
    {
        if ('active' !== $this->accountState || !$this->verified || 'approved' !== $this->decision || !$this->currentReview || !$this->matchingSubmitter || null !== $this->ownerAccountId || $this->alreadyOwnsRace) {
            return false;
        }
        if ('document' === $this->source) {
            return true;
        }

        return 'import' === $this->source && 'participating' === $this->participation && null !== $this->originalEmail && strtolower(trim($this->originalEmail)) === strtolower(trim($this->accountEmail));
    }
}
