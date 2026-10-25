<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Domain\Finance\SellerIdentity;
use App\Domain\Finance\SellerReadiness;

interface SellerProvider
{
    public function configurationFingerprint(): string;

    public function assertConfigured(): void;

    public function createRecipient(SellerIdentity $identity, string $operationId, string $idempotencyKey): string;

    public function readiness(string $providerReference): SellerReadiness;

    public function onboardingLink(string $providerReference): string;
}
