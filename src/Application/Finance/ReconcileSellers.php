<?php

declare(strict_types=1);

namespace App\Application\Finance;

final readonly class ReconcileSellers
{
    public function __construct(private SellerOnboardingStore $store, private SellerOnboarding $onboarding, private string $scope, private int $freshnessSeconds = 300)
    {
    }

    /**
     * Re-confirms sellers whose transfer readiness is missing, so an interrupted or failed
     * confirmation is retried instead of blocking their sales forever.
     *
     * @return array{reconciled:int,failed:int}
     */
    public function reconcileDue(\DateTimeImmutable $now, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000.');
        }
        $reconciled = 0;
        $failed = 0;
        $staleBefore = $now->sub(new \DateInterval('PT'.$this->freshnessSeconds.'S'));
        foreach ($this->store->needingReconciliation($this->scope, $staleBefore, $limit) as $sellerId) {
            try {
                $this->onboarding->reconcile($sellerId, $now);
                ++$reconciled;
            } catch (\RuntimeException|\DomainException) {
                ++$failed;
            }
        }

        return ['reconciled' => $reconciled, 'failed' => $failed];
    }
}
