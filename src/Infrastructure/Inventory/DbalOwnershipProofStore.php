<?php

declare(strict_types=1);

namespace App\Infrastructure\Inventory;

use App\Application\Inventory\OwnershipProofStore;
use App\Application\Inventory\QuarantinedProof;
use App\Application\Inventory\StoredProof;
use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Domain\Identity\Actor;
use App\Domain\Inventory\BibNumber;
use App\Domain\Inventory\ProofRejection;
use Doctrine\DBAL\Connection;

final readonly class DbalOwnershipProofStore implements OwnershipProofStore
{
    public function __construct(private Connection $connection, private AuditWriter $audit)
    {
    }

    public function documentForReview(string $reviewerId, string $proofId): ?StoredProof
    {
        $roles = $this->connection->fetchOne("SELECT roles FROM accounts WHERE id=? AND lifecycle_state='active' AND verified_at IS NOT NULL", [$reviewerId]);
        if (!$this->reviewer($reviewerId, $roles)?->canAdministerAccounts()) {
            return null;
        }
        $row = $this->connection->fetchAssociative("SELECT o.id,o.object_key,o.size_bytes,o.checksum FROM ownership_proofs p JOIN private_objects o ON o.id=p.private_object_id WHERE p.id=? AND p.source_kind='document' AND o.category='ownership_proof' AND o.purged_at IS NULL", [$proofId]);
        if (false === $row) {
            return null;
        }
        if (!is_string($row['id']) || !is_string($row['object_key']) || !is_int($row['size_bytes']) || !is_string($row['checksum'])) {
            throw new \UnexpectedValueException('Invalid stored proof metadata.');
        }

        return new StoredProof($row['id'], $row['object_key'], $row['size_bytes'], $row['checksum']);
    }

    public function canSubmitDocument(string $accountId, string $raceId, BibNumber $bib): bool
    {
        return false !== $this->connection->fetchOne("SELECT r.id FROM races r JOIN accounts a ON a.id=? WHERE r.id=? AND r.publication_state='published' AND r.participation='nonparticipating' AND a.lifecycle_state='active' AND a.verified_at IS NOT NULL AND NOT EXISTS(SELECT 1 FROM bib_entitlements e WHERE e.race_id=r.id AND e.bib_number=? AND (e.source_kind<>'document' OR (e.current_owner_account_id IS NOT NULL AND e.current_owner_account_id<>a.id)))", [$accountId, $raceId, $bib->value]);
    }

    public function submitDocument(string $accountId, string $raceId, BibNumber $bib, string $proofId, QuarantinedProof $object, string $auditId, \DateTimeImmutable $now): bool
    {
        return $this->connection->transactional(function () use ($accountId, $raceId, $bib, $proofId, $object, $auditId, $now): bool {
            $this->connection->executeQuery('SELECT id FROM races WHERE id=? FOR UPDATE', [$raceId]);
            $this->connection->executeQuery('SELECT id FROM accounts WHERE id=? FOR UPDATE', [$accountId]);
            $this->connection->executeQuery('SELECT id FROM bib_entitlements WHERE race_id=? AND bib_number=? FOR UPDATE', [$raceId, $bib->value]);
            if (!$this->canSubmitDocument($accountId, $raceId, $bib) || false !== $this->connection->fetchOne('SELECT id FROM ownership_proofs WHERE id=?', [$proofId])) {
                return false;
            }
            $entitlement = $this->connection->fetchOne('SELECT id FROM bib_entitlements WHERE race_id=? AND bib_number=?', [$raceId, $bib->value]);
            $this->connection->insert('private_objects', ['id' => $object->id, 'object_key' => $object->objectKey, 'category' => 'ownership_proof', 'mime_type' => $object->mimeType, 'size_bytes' => $object->sizeBytes, 'checksum' => $object->checksum, 'uploaded_by' => $accountId, 'created_at' => $now->format(DATE_ATOM)]);
            $this->connection->insert('ownership_proofs', ['id' => $proofId, 'submitted_by' => $accountId, 'race_id' => $raceId, 'proposed_bib_number' => $bib->value, 'entitlement_id' => false === $entitlement ? null : $entitlement, 'source_kind' => 'document', 'private_object_id' => $object->id, 'submitted_at' => $now->format(DATE_ATOM)]);
            $this->audit->append(new AuditEntry($auditId, $accountId, null, '{"scope":"document_submitter","proof_version":1}', 'ownership_proof.document_submitted', 'ownership_proof', $proofId, 'Document submitted for review', $now, 'completed'));

            return true;
        });
    }

    public function imported(string $accountId, string $entitlementId, string $proofId, string $auditId, \DateTimeImmutable $now): bool
    {
        return $this->connection->transactional(function () use ($accountId, $entitlementId, $proofId, $auditId, $now): bool {
            $raceId = $this->connection->fetchOne('SELECT race_id FROM bib_entitlements WHERE id=?', [$entitlementId]);
            if (!is_string($raceId)) {
                return false;
            }
            $this->connection->executeQuery('SELECT id FROM races WHERE id=? FOR UPDATE', [$raceId]);
            $row = $this->connection->fetchAssociative("SELECT e.race_id,e.bib_number FROM bib_entitlements e JOIN races r ON r.id=e.race_id JOIN accounts a ON a.id=? JOIN account_private_profiles p ON p.account_id=a.id WHERE e.id=? AND e.source_kind='import' AND r.participation='participating' AND a.lifecycle_state='active' AND a.verified_at IS NOT NULL AND lower(btrim(e.original_runner_email))=p.email_normalized AND e.current_owner_account_id IS NULL FOR UPDATE OF e,a,p", [$accountId, $entitlementId]);
            if (false === $row || false !== $this->connection->fetchOne('SELECT id FROM ownership_proofs WHERE id=?', [$proofId])) {
                return false;
            }
            $this->connection->insert('ownership_proofs', ['id' => $proofId, 'submitted_by' => $accountId, 'race_id' => $row['race_id'], 'proposed_bib_number' => $row['bib_number'], 'entitlement_id' => $entitlementId, 'source_kind' => 'import', 'submitted_at' => $now->format(DATE_ATOM), 'decision' => 'approved', 'reviewed_version' => 1, 'reviewer_id' => $accountId, 'reviewed_at' => $now->format(DATE_ATOM), 'review_reason' => 'Verified email matches imported holder']);
            $this->audit->append(new AuditEntry($auditId, $accountId, null, '{"scope":"verified_import_email","proof_version":1}', 'ownership_proof.import_verified', 'ownership_proof', $proofId, 'Verified email matches imported holder', $now, 'completed'));

            return true;
        });
    }

    public function reject(string $reviewerId, string $proofId, int $expectedVersion, ProofRejection $rejection, string $auditId, \DateTimeImmutable $now): bool
    {
        return $this->connection->transactional(function () use ($reviewerId, $proofId, $expectedVersion, $rejection, $auditId, $now): bool {
            $raceId = $this->connection->fetchOne('SELECT race_id FROM ownership_proofs WHERE id=?', [$proofId]);
            if (!is_string($raceId)) {
                return false;
            }
            $this->connection->executeQuery('SELECT id FROM races WHERE id=? FOR UPDATE', [$raceId]);
            $proof = $this->connection->fetchAssociative("SELECT id FROM ownership_proofs WHERE id=? AND source_kind='document' AND decision IS NULL AND proof_version=? FOR UPDATE", [$proofId, $expectedVersion]);
            if (false === $proof) {
                return false;
            }
            $storedRoles = $this->connection->fetchOne("SELECT roles FROM accounts WHERE id=? AND lifecycle_state='active' AND verified_at IS NOT NULL FOR UPDATE", [$reviewerId]);
            $actor = $this->reviewer($reviewerId, $storedRoles);
            if (!$rejection->allows($actor)) {
                return false;
            }
            $this->connection->update('ownership_proofs', ['decision' => 'rejected', 'reviewed_version' => $expectedVersion, 'reviewer_id' => $reviewerId, 'reviewed_at' => $now->format(DATE_ATOM), 'review_reason' => $rejection->reason], ['id' => $proofId]);
            $this->audit->append(new AuditEntry($auditId, $reviewerId, null, json_encode(['scope' => 'document_moderator', 'roles' => $actor?->roles, 'proof_version' => $expectedVersion], JSON_THROW_ON_ERROR), 'ownership_proof.document_rejected', 'ownership_proof', $proofId, $rejection->reason, $now, 'completed'));

            return true;
        });
    }

    private function reviewer(string $reviewerId, mixed $storedRoles): ?Actor
    {
        if (!is_string($storedRoles)) {
            return null;
        }
        $values = json_decode($storedRoles, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($values) || !array_is_list($values)) {
            throw new \UnexpectedValueException('Invalid stored reviewer roles.');
        }
        $roles = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \UnexpectedValueException('Invalid stored reviewer role.');
            }
            $roles[] = $value;
        }

        return new Actor($reviewerId, $roles);
    }
}
