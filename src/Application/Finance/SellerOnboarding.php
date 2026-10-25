<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Domain\Finance\SellerReadiness;

final readonly class SellerOnboarding
{
    public function __construct(private SellerOnboardingStore $store, private SellerProvider $provider, private string $providerScope)
    {
    }

    public function start(string $actorAccountId, string $country, \DateTimeImmutable $now): SellerOnboardingView
    {
        $this->provider->assertConfigured();
        $identity = $this->store->identity($actorAccountId, $country);
        $reference = $this->store->reference($actorAccountId, $this->providerScope);
        if (null === $reference) {
            $operation = $this->store->prepare($actorAccountId, $this->providerScope, $identity, $this->provider->configurationFingerprint(), $now);
            if ($operation->createdAt <= $now->sub(new \DateInterval('P29D'))) {
                throw new \DomainException('Seller creation requires provider reconciliation before replay.');
            }
            $generation = $this->store->submitted($operation->id, $now);
            try {
                $reference = $this->provider->createRecipient($operation->identity, $operation->id, 'seller-account:'.$operation->id);
            } catch (\RuntimeException $exception) {
                $this->store->unknown($operation->id, $generation);
                throw $exception;
            }
            $this->store->complete($actorAccountId, $this->providerScope, $reference, $now, $generation);
        }
        $this->store->recordReadiness($actorAccountId, $this->providerScope, SellerReadiness::Pending, $now);
        $readiness = $this->provider->readiness($reference);
        $this->store->recordReadiness($actorAccountId, $this->providerScope, $readiness, $now);

        return new SellerOnboardingView($reference, $readiness->value, $readiness->canPublish() ? null : $this->provider->onboardingLink($reference));
    }

    public function reconcile(string $actorAccountId, \DateTimeImmutable $now): SellerOnboardingView
    {
        $this->provider->assertConfigured();
        $this->store->assertSeller($actorAccountId);
        $reference = $this->store->reference($actorAccountId, $this->providerScope);
        if (null === $reference) {
            throw new \DomainException('Seller recipient account is not configured.');
        }
        $this->store->recordReadiness($actorAccountId, $this->providerScope, SellerReadiness::Pending, $now);
        $readiness = $this->provider->readiness($reference);
        $this->store->recordReadiness($actorAccountId, $this->providerScope, $readiness, $now);

        return new SellerOnboardingView($reference, $readiness->value, null);
    }
}
