<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\SellerCreationOperation;
use App\Application\Finance\SellerOnboardingStore;
use App\Domain\Finance\SellerIdentity;
use App\Domain\Finance\SellerReadiness;
use Doctrine\DBAL\Connection;

final readonly class DbalSellerOnboardingStore implements SellerOnboardingStore
{
    public function __construct(private Connection $database)
    {
    }

    public function assertSeller(string $sellerId): void
    {
        $eligible = $this->database->fetchOne("SELECT id FROM accounts WHERE id=? AND lifecycle_state='active' AND verified_at IS NOT NULL", [$sellerId]);
        if (!is_string($eligible)) {
            throw new \DomainException('Seller is not active and verified.');
        }
    }

    public function identity(string $sellerId, string $country): SellerIdentity
    {
        $email = $this->database->fetchOne("SELECT p.email FROM account_private_profiles p JOIN accounts a ON a.id=p.account_id WHERE a.id=? AND a.lifecycle_state='active' AND a.verified_at IS NOT NULL", [$sellerId]);
        if (!is_string($email)) {
            throw new \DomainException('Seller identity is unavailable.');
        }

        return new SellerIdentity($country, $email);
    }

    public function reference(string $sellerId, string $scope): ?string
    {
        $reference = $this->database->fetchOne("SELECT connected_account_ref FROM seller_accounts WHERE seller_id=? AND provider='stripe' AND provider_scope=?", [$sellerId, $scope]);

        return is_string($reference) ? $reference : null;
    }

    public function prepare(string $sellerId, string $scope, SellerIdentity $identity, string $configurationFingerprint, \DateTimeImmutable $now): SellerCreationOperation
    {
        $existing = $this->database->fetchAssociative("SELECT id, parameters, private_payload, created_at FROM seller_onboarding_operations WHERE seller_id=? AND provider='stripe' AND provider_scope=?", [$sellerId, $scope]);
        if (false !== $existing) {
            if (!is_string($existing['id']) || !is_string($existing['parameters']) || !is_string($existing['private_payload']) || !is_string($existing['created_at'])) {
                throw new \DomainException('Prepared seller identity is unavailable for replay.');
            }
            $parameters = json_decode($existing['parameters'], true, 512, JSON_THROW_ON_ERROR);
            $private = json_decode($existing['private_payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($parameters) || !is_string($parameters['country'] ?? null) || !is_array($private) || !is_string($private['email'] ?? null)) {
                throw new \UnexpectedValueException('Invalid prepared seller identity.');
            }

            if (($parameters['provider_configuration'] ?? null) !== $configurationFingerprint) {
                throw new \DomainException('Seller creation configuration changed; reconciliation is required.');
            }

            return new SellerCreationOperation($existing['id'], new SellerIdentity($parameters['country'], $private['email']), new \DateTimeImmutable($existing['created_at']));
        }
        $operationId = $this->database->fetchOne('SELECT gen_random_uuid()::text');
        if (!is_string($operationId)) {
            throw new \RuntimeException('Unable to generate operation identity.');
        }
        $parameters = json_encode(['country' => $identity->country, 'provider_configuration' => $configurationFingerprint], JSON_THROW_ON_ERROR);
        $this->database->insert('seller_onboarding_operations', ['id' => $operationId, 'seller_id' => $sellerId, 'provider' => 'stripe', 'provider_scope' => $scope, 'idempotency_key' => 'seller-account:'.$operationId, 'parameters' => $parameters, 'parameters_hash' => hash('sha256', $parameters), 'private_payload' => json_encode(['email' => $identity->email], JSON_THROW_ON_ERROR), 'state' => 'prepared', 'created_at' => $now->format('Y-m-d H:i:s.uP')]);

        return new SellerCreationOperation($operationId, $identity, $now);
    }

    public function submitted(string $operationId, \DateTimeImmutable $now): int
    {
        $generation = $this->database->fetchOne("UPDATE seller_onboarding_operations SET state='submitted', submitted_at=COALESCE(submitted_at,?), claimed_by=gen_random_uuid(), lease_until=?, fencing_generation=fencing_generation+1 WHERE id=? AND state IN('prepared','submitted','unknown') AND (lease_until IS NULL OR lease_until<=?) RETURNING fencing_generation", [$now->format('Y-m-d H:i:s.uP'), $now->add(new \DateInterval('PT5M'))->format('Y-m-d H:i:s.uP'), $operationId, $now->format('Y-m-d H:i:s.uP')]);
        if (!is_int($generation) && !is_string($generation)) {
            throw new \DomainException('Seller creation is already claimed or resolved.');
        }

        return (int) $generation;
    }

    public function unknown(string $operationId, int $generation): void
    {
        $this->database->executeStatement("UPDATE seller_onboarding_operations SET state='unknown', claimed_by=NULL, lease_until=NULL WHERE id=? AND state='submitted' AND fencing_generation=?", [$operationId, $generation]);
    }

    public function complete(string $sellerId, string $scope, string $reference, \DateTimeImmutable $now, int $generation): void
    {
        $this->database->transactional(function () use ($sellerId, $scope, $reference, $now, $generation): void {
            $operationId = $this->database->fetchOne("SELECT id FROM seller_onboarding_operations WHERE seller_id=? AND provider='stripe' AND provider_scope=?", [$sellerId, $scope]);
            $resolved = $this->database->executeStatement("UPDATE seller_onboarding_operations SET state='succeeded', provider_ref=?, resolved_at=?, claimed_by=NULL, lease_until=NULL WHERE id=? AND fencing_generation=? AND state='submitted'", [$reference, $now->format('Y-m-d H:i:s.uP'), $operationId, $generation]);
            if (1 !== $resolved) {
                throw new \DomainException('Seller creation completion lost its claim.');
            }
            $this->database->insert('seller_accounts', ['id' => $operationId, 'seller_id' => $sellerId, 'provider' => 'stripe', 'provider_scope' => $scope, 'connected_account_ref' => $reference, 'readiness_state' => 'pending']);
        });
    }

    public function recordReadiness(string $sellerId, string $scope, SellerReadiness $readiness, \DateTimeImmutable $now): void
    {
        $this->database->executeStatement('UPDATE seller_accounts SET readiness_state=?, last_reconciled_at=?, version=version+1 WHERE seller_id=? AND provider=\'stripe\' AND provider_scope=?', [$readiness->value, $now->format('Y-m-d H:i:s.uP'), $sellerId, $scope]);
    }

    public function needingReconciliation(string $scope, \DateTimeImmutable $staleBefore, int $limit): array
    {
        $sellers = $this->database->fetchFirstColumn("SELECT seller_id::text FROM seller_accounts WHERE provider='stripe' AND provider_scope=? AND readiness_state<>'ready' AND (last_reconciled_at IS NULL OR last_reconciled_at<?) ORDER BY last_reconciled_at NULLS FIRST, seller_id::text LIMIT ?", [$scope, $staleBefore->format('Y-m-d H:i:s.uP'), $limit]);
        foreach ($sellers as $seller) {
            assert(is_string($seller));
        }

        return $sellers;
    }
}
