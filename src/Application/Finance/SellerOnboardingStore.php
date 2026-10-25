<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Domain\Finance\SellerIdentity;
use App\Domain\Finance\SellerReadiness;

interface SellerOnboardingStore
{
    public function assertSeller(string $sellerId): void;

    public function identity(string $sellerId, string $country): SellerIdentity;

    public function reference(string $sellerId, string $scope): ?string;

    public function prepare(string $sellerId, string $scope, SellerIdentity $identity, string $configurationFingerprint, \DateTimeImmutable $now): SellerCreationOperation;

    public function submitted(string $operationId, \DateTimeImmutable $now): int;

    public function unknown(string $operationId, int $generation): void;

    public function complete(string $sellerId, string $scope, string $reference, \DateTimeImmutable $now, int $generation): void;

    public function recordReadiness(string $sellerId, string $scope, SellerReadiness $readiness, \DateTimeImmutable $now): void;

    /**
     * Sellers whose confirmed transfer readiness is missing or stale.
     *
     * @return list<string>
     */
    public function needingReconciliation(string $scope, \DateTimeImmutable $staleBefore, int $limit): array;
}
