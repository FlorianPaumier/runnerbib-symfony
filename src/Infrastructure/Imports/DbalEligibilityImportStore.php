<?php

declare(strict_types=1);

namespace App\Infrastructure\Imports;

use App\Application\Imports\EligibilityImportStore;
use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Domain\Imports\ImportMode;
use Doctrine\DBAL\Connection;

final readonly class DbalEligibilityImportStore implements EligibilityImportStore
{
    public function __construct(private Connection $connection, private AuditWriter $audit)
    {
    }

    public function lockScope(string $accountId, string $raceId): void
    {
        $race = $this->connection->fetchAssociative('SELECT organization_id,participation FROM races WHERE id=? FOR UPDATE', [$raceId]);
        if (false === $race || 'participating' !== $race['participation'] || !is_string($race['organization_id'])) {
            throw new \DomainException('Participating organizer race required.');
        }
        $this->connection->executeQuery('SELECT id FROM organizations WHERE id=? FOR UPDATE', [$race['organization_id']]);
        $this->connection->executeQuery('SELECT id FROM accounts WHERE id=? FOR UPDATE', [$accountId]);
        $this->connection->executeQuery('SELECT id FROM organization_memberships WHERE organization_id=? AND account_id=? AND ended_at IS NULL FOR UPDATE', [$race['organization_id'], $accountId]);
    }

    public function persist(string $accountId, string $raceId, string $importId, array $rows, \DateTimeImmutable $now, ImportMode $mode): array
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('An owning import transaction is required.');
        }
        $existing = $this->connection->fetchAssociative('SELECT actor_id,race_id,state,row_results FROM eligibility_imports WHERE id=? FOR UPDATE', [$importId]);
        if (false !== $existing) {
            if ($existing['actor_id'] !== $accountId || $existing['race_id'] !== $raceId || 'completed' !== $existing['state'] || !is_string($existing['row_results'])) {
                throw new \DomainException('Existing import cannot be reused.');
            }
            $stored = json_decode($existing['row_results'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($stored) || !array_is_list($stored)) {
                throw new \UnexpectedValueException('Invalid stored import results.');
            }
            $results = [];
            foreach ($stored as $row) {
                if (!is_array($row) || !is_int($row['row'] ?? null) || !in_array($row['status'] ?? null, ['imported', 'updated', 'protected', 'duplicate', 'invalid'], true)) {
                    throw new \UnexpectedValueException('Invalid stored import result.');
                }
                $result = ['row' => $row['row'], 'status' => $row['status']];
                foreach (['bib', 'reason'] as $field) {
                    if (array_key_exists($field, $row)) {
                        if (!is_string($row[$field])) {
                            throw new \UnexpectedValueException('Invalid stored import result value.');
                        }
                        $result[$field] = $row[$field];
                    }
                }
                $results[] = $result;
            }

            return $results;
        }
        $this->connection->insert('eligibility_imports', ['id' => $importId, 'race_id' => $raceId, 'actor_id' => $accountId, 'mode' => $mode->value, 'state' => 'processing', 'started_at' => $now->format(DATE_ATOM)]);
        $results = [];
        foreach ($rows as $row) {
            if ('valid' !== $row['status']) {
                $results[] = $row;
                continue;
            }
            if (!isset($row['bib'], $row['email']) || !array_key_exists('name', $row)) {
                throw new \UnexpectedValueException('Invalid preview row.');
            }
            $added = $this->connection->executeStatement("INSERT INTO bib_entitlements(id,race_id,bib_number,source_kind,import_id,original_holder_name,original_runner_email) VALUES(gen_random_uuid(),?,?,'import',?,?,?) ON CONFLICT(race_id,bib_number) DO NOTHING", [$raceId, $row['bib'], $importId, $row['name'], $row['email']]);
            $status = 1 === $added ? 'imported' : 'duplicate';
            if (0 === $added && 'update' === $mode->value) {
                $entitlement = $this->connection->fetchAssociative('SELECT id,current_owner_account_id,source_kind FROM bib_entitlements WHERE race_id=? AND bib_number=? FOR UPDATE', [$raceId, $row['bib']]);
                if (false === $entitlement || 'import' !== $entitlement['source_kind'] || null !== $entitlement['current_owner_account_id'] || false !== $this->connection->fetchOne('SELECT id FROM resale_listings WHERE entitlement_id=?', [$entitlement['id']])) {
                    $status = 'protected';
                } else {
                    $updates = [];
                    foreach ($mode->selectedFields as $field) {
                        $updates['name' === $field ? 'original_holder_name' : 'original_runner_email'] = $row[$field];
                    }
                    $this->connection->update('bib_entitlements', $updates, ['id' => $entitlement['id']]);
                    $this->connection->executeStatement('UPDATE bib_entitlements SET version=version+1 WHERE id=?', [$entitlement['id']]);
                    $this->connection->executeStatement("UPDATE ownership_proofs SET proof_version=proof_version+1,decision=NULL,reviewed_version=NULL,reviewer_id=NULL,reviewed_at=NULL,review_reason=NULL WHERE entitlement_id=? AND source_kind='import'", [$entitlement['id']]);
                    $status = 'updated';
                }
            }
            $results[] = ['row' => $row['row'], 'status' => $status, 'bib' => $row['bib']];
        }
        $this->connection->update('eligibility_imports', ['state' => 'completed', 'completed_at' => $now->format(DATE_ATOM), 'row_results' => json_encode($results, JSON_THROW_ON_ERROR), 'summary' => json_encode(['records' => count($results)], JSON_THROW_ON_ERROR)], ['id' => $importId]);
        $auditId = $this->connection->fetchOne('SELECT gen_random_uuid()');
        if (!is_string($auditId)) {
            throw new \UnexpectedValueException('Invalid generated audit identifier.');
        }
        $this->audit->append(new AuditEntry($auditId, $accountId, null, json_encode(['scope' => 'organization_race', 'race_id' => $raceId, 'mode' => $mode->value], JSON_THROW_ON_ERROR), 'eligibility_import.completed', 'eligibility_import', $importId, null, $now, 'completed'));

        return $results;
    }
}
