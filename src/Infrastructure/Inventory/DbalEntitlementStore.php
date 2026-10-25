<?php

declare(strict_types=1);

namespace App\Infrastructure\Inventory;

use App\Application\Inventory\EntitlementStore;
use App\Domain\Inventory\BibNumber;
use App\Domain\Inventory\ClaimEligibility;
use Doctrine\DBAL\Connection;

final readonly class DbalEntitlementStore implements EntitlementStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function recordImported(string $id, string $raceId, string $importId, BibNumber $bibNumber, ?string $originalHolderName, string $originalEmail): bool
    {
        return 1 === $this->connection->executeStatement("INSERT INTO bib_entitlements(id,race_id,bib_number,source_kind,import_id,original_holder_name,original_runner_email) VALUES (?,?,?,'import',?,?,?) ON CONFLICT (race_id,bib_number) DO NOTHING", [$id, $raceId, $bibNumber->value, $importId, $originalHolderName, strtolower(trim($originalEmail))]);
    }

    public function claim(string $accountId, string $entitlementId, string $proofId, string $transitionId, \DateTimeImmutable $now): bool
    {
        return $this->connection->transactional(function () use ($accountId, $entitlementId, $proofId, $transitionId, $now): bool {
            $raceId = $this->connection->fetchOne('SELECT race_id FROM bib_entitlements WHERE id=?', [$entitlementId]);
            if (!is_string($raceId)) {
                return false;
            }
            $this->connection->executeQuery('SELECT id FROM races WHERE id=? FOR UPDATE', [$raceId]);
            if (false === $this->connection->fetchOne('SELECT id FROM accounts WHERE id=?', [$accountId])) {
                return false;
            }
            $this->connection->executeStatement('INSERT INTO buyer_race_guards(buyer_id,race_id) VALUES (?,?) ON CONFLICT DO NOTHING', [$accountId, $raceId]);
            $guard = $this->connection->fetchAssociative('SELECT reserved_checkout_id FROM buyer_race_guards WHERE buyer_id=? AND race_id=? FOR UPDATE', [$accountId, $raceId]);
            if (false === $guard || null !== $guard['reserved_checkout_id']) {
                return false;
            }
            $this->connection->executeQuery('SELECT id FROM bib_entitlements WHERE id=? FOR UPDATE', [$entitlementId]);
            $row = $this->connection->fetchAssociative('SELECT a.lifecycle_state,a.verified_at,pr.email_normalized,r.participation,e.source_kind,e.original_runner_email,e.current_owner_account_id,p.decision,p.proof_version,p.reviewed_version,p.submitted_by,p.proposed_bib_number,e.bib_number FROM bib_entitlements e JOIN races r ON r.id=e.race_id JOIN ownership_proofs p ON p.entitlement_id=e.id AND p.id=? JOIN accounts a ON a.id=? JOIN account_private_profiles pr ON pr.account_id=a.id WHERE e.id=? AND e.race_id=? AND p.race_id=e.race_id AND p.source_kind=e.source_kind FOR UPDATE OF p,a,pr', [$proofId, $accountId, $entitlementId, $raceId]);
            if (false === $row) {
                return false;
            }
            foreach (['lifecycle_state', 'email_normalized', 'participation', 'source_kind'] as $key) {
                if (!is_string($row[$key])) {
                    throw new \UnexpectedValueException('Invalid stored claim data.');
                }
            }
            $alreadyOwns = false !== $this->connection->fetchOne('SELECT id FROM bib_entitlements WHERE race_id=? AND current_owner_account_id=?', [$raceId, $accountId]);
            $eligibility = new ClaimEligibility($row['lifecycle_state'], null !== $row['verified_at'], $row['participation'], $row['source_kind'], is_string($row['original_runner_email']) ? $row['original_runner_email'] : null, $row['email_normalized'], is_string($row['decision']) ? $row['decision'] : null, $row['proof_version'] === $row['reviewed_version'] && $row['proposed_bib_number'] === $row['bib_number'], $row['submitted_by'] === $accountId, is_string($row['current_owner_account_id']) ? $row['current_owner_account_id'] : null, $alreadyOwns);
            if (!$eligibility->allows()) {
                return false;
            }
            $this->connection->executeStatement('UPDATE bib_entitlements SET current_owner_account_id=?,version=version+1 WHERE id=?', [$accountId, $entitlementId]);
            $sequence = $this->connection->fetchOne('SELECT COALESCE(max(sequence_no),0)+1 FROM ownership_transitions WHERE entitlement_id=?', [$entitlementId]);
            $this->connection->insert('ownership_transitions', ['id' => $transitionId, 'entitlement_id' => $entitlementId, 'sequence_no' => $sequence, 'to_account_id' => $accountId, 'kind' => 'verified_claim', 'claim_proof_id' => $proofId, 'occurred_at' => $now->format(DATE_ATOM), 'effect_key' => 'verified-claim:'.$entitlementId]);

            return true;
        });
    }
}
