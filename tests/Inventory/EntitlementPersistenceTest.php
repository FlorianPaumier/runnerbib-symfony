<?php

declare(strict_types=1);

namespace App\Tests\Inventory;

use App\Infrastructure\Inventory\DbalEntitlementStore;
use App\Tests\Support\IsolatedWebTestCase;

final class EntitlementPersistenceTest extends IsolatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("INSERT INTO account_private_profiles(account_id,email,email_normalized) VALUES ('00000000-0000-0000-0000-000000000002','buyer@example.test','buyer@example.test')");
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-08Z' WHERE id='00000000-0000-0000-0000-000000000002'");
        $this->database->executeStatement("INSERT INTO races(id,organization_id,name,starts_at,timezone,sport_code,distance_metres,city,country_code,created_at,updated_at,participation) VALUES ('00000000-0000-0000-0000-000000000019','00000000-0000-0000-0000-000000000003','Import race','2027-04-03Z','Europe/Paris','running',10000,'Paris','FR','2026-10-08Z','2026-10-08Z','participating')");
        $this->database->executeStatement("INSERT INTO eligibility_imports(id,race_id,actor_id,mode,state,started_at) VALUES ('00000000-0000-0000-0000-000000000020','00000000-0000-0000-0000-000000000019','00000000-0000-0000-0000-000000000001','skip_invalid','completed','2026-10-08Z')");
    }

    public function testExistingBuyerCheckoutGuardPreventsOwnershipClaimWithoutTransition(): void
    {
        $this->database->executeStatement("INSERT INTO buyer_race_guards(buyer_id,race_id,reserved_checkout_id) VALUES ('00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000011')");
        self::assertFalse((new DbalEntitlementStore($this->database))->claim('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000006', '00000000-0000-0000-0000-000000000008', '00000000-0000-0000-0000-000000000070', new \DateTimeImmutable('2026-10-13Z')));
        self::assertSame('00000000-0000-0000-0000-000000000001', $this->database->fetchOne('SELECT current_owner_account_id::text FROM bib_entitlements'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM ownership_transitions'));
        self::assertSame('00000000-0000-0000-0000-000000000011', $this->database->fetchOne('SELECT reserved_checkout_id::text FROM buyer_race_guards'));
        self::assertSame('protected', $this->database->fetchOne('SELECT protection_state FROM checkout_items'));
    }

    public function testRealPdoStringifiedProofMetadataFailsClosedBeforeBlobRead(): void
    {
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z',roles='[\"admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
        $connection = $this->stringifiedDatabase();
        $download = new \App\Application\Inventory\DownloadOwnershipProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($connection, new \App\Infrastructure\Operations\DbalAuditWriter($connection)), new \App\Infrastructure\Inventory\LocalProofQuarantine());
        try {
            $download->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000008');
            self::fail('Stringified proof metadata must fail before unconfigured blob storage is called.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame('Invalid stored proof metadata.', $failure->getMessage());
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM private_objects'));
            self::assertSame('approved', $this->database->fetchOne('SELECT decision FROM ownership_proofs'));
            self::assertSame('00000000-0000-0000-0000-000000000001', $this->database->fetchOne('SELECT current_owner_account_id FROM bib_entitlements'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        } finally {
            $connection->close();
        }
    }

    public function testMatchingVerifiedHolderCanSubmitAuditedImportProof(): void
    {
        $store = new DbalEntitlementStore($this->database);
        $store->recordImported('00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000019', '00000000-0000-0000-0000-000000000020', new \App\Domain\Inventory\BibNumber('00123'), 'Original Runner', 'BUYER@example.test');
        $proofs = new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $submit = new \App\Application\Inventory\SubmitOwnershipProof($proofs);
        self::assertTrue($submit->imported('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        $proof = $this->database->fetchAssociative("SELECT source_kind,decision,reviewed_version,reviewer_id,review_reason FROM ownership_proofs WHERE id='00000000-0000-0000-0000-000000000022'");
        self::assertSame(['source_kind' => 'import', 'decision' => 'approved', 'reviewed_version' => 1, 'reviewer_id' => '00000000-0000-0000-0000-000000000002', 'review_reason' => 'Verified email matches imported holder'], $proof);
        self::assertSame('ownership_proof.import_verified', $this->database->fetchOne('SELECT action FROM administrative_audit'));
        self::assertTrue((new \App\Application\Inventory\ClaimEntitlement($store))->execute('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000024', new \DateTimeImmutable('2026-10-10T12:00:00Z')));
    }

    public function testImportProofRetryDoesNotCreateOrAuditAnotherApproval(): void
    {
        $store = new DbalEntitlementStore($this->database);
        $store->recordImported('00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000019', '00000000-0000-0000-0000-000000000020', new \App\Domain\Inventory\BibNumber('00123'), 'Original Runner', 'buyer@example.test');
        $submit = new \App\Application\Inventory\SubmitOwnershipProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)));
        $now = new \DateTimeImmutable('2026-10-10T12:00:00Z');
        self::assertTrue($submit->imported('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', $now));
        self::assertFalse($submit->imported('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000024', $now));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='ownership_proof.import_verified'"));
    }

    public function testImportProofRejectsWrongHolderUnverifiedAndNonparticipatingRace(): void
    {
        $store = new DbalEntitlementStore($this->database);
        $store->recordImported('00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000019', '00000000-0000-0000-0000-000000000020', new \App\Domain\Inventory\BibNumber('00123'), 'Original Runner', 'buyer@example.test');
        $submit = new \App\Application\Inventory\SubmitOwnershipProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)));
        $now = new \DateTimeImmutable('2026-10-10T12:00:00Z');
        self::assertFalse($submit->imported('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000099', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', $now));
        self::assertFalse($submit->imported('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', $now));
        $this->database->executeStatement("UPDATE accounts SET verified_at=NULL WHERE id='00000000-0000-0000-0000-000000000002'");
        self::assertFalse($submit->imported('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', $now));
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-10Z' WHERE id='00000000-0000-0000-0000-000000000002'");
        $this->database->executeStatement("UPDATE races SET participation='nonparticipating' WHERE id='00000000-0000-0000-0000-000000000019'");
        self::assertFalse($submit->imported('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', $now));
        self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM ownership_proofs WHERE id='00000000-0000-0000-0000-000000000022'"));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testImportProofAuditFailureLeavesNoApprovedEvidence(): void
    {
        $store = new DbalEntitlementStore($this->database);
        $store->recordImported('00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000019', '00000000-0000-0000-0000-000000000020', new \App\Domain\Inventory\BibNumber('00123'), 'Original Runner', 'buyer@example.test');
        $this->database->executeStatement('ALTER TABLE administrative_audit ADD CONSTRAINT reject_proof_audit CHECK(false)');
        $submit = new \App\Application\Inventory\SubmitOwnershipProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)));
        try {
            $submit->imported('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', new \DateTimeImmutable('2026-10-10T12:00:00Z'));
            self::fail('Audit failure must roll back approval.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM ownership_proofs WHERE id='00000000-0000-0000-0000-000000000022'"));
        }
    }

    public function testAdministratorRejectsPendingDocumentProofAtExactVersionWithAudit(): void
    {
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z',roles='[\"admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
        $this->database->executeStatement("INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,source_kind,private_object_id,submitted_at) VALUES ('00000000-0000-0000-0000-000000000030','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000004','00999','document','00000000-0000-0000-0000-000000000007','2026-10-11Z')");
        $review = new \App\Application\Inventory\ReviewOwnershipProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)));
        self::assertTrue($review->reject('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030', 1, '  Document does not identify this race  ', '00000000-0000-0000-0000-000000000031', new \DateTimeImmutable('2026-10-11T12:00:00Z')));
        self::assertSame(['decision' => 'rejected', 'reviewed_version' => 1, 'reviewer_id' => '00000000-0000-0000-0000-000000000001', 'review_reason' => 'Document does not identify this race'], $this->database->fetchAssociative("SELECT decision,reviewed_version,reviewer_id,review_reason FROM ownership_proofs WHERE id='00000000-0000-0000-0000-000000000030'"));
        self::assertSame('ownership_proof.document_rejected', $this->database->fetchOne('SELECT action FROM administrative_audit'));
    }

    public function testDocumentRejectionRequiresVerifiedAdministratorCurrentVersionAndPendingDecision(): void
    {
        $this->database->executeStatement("INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,source_kind,private_object_id,submitted_at) VALUES ('00000000-0000-0000-0000-000000000030','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000004','00999','document','00000000-0000-0000-0000-000000000007','2026-10-11Z')");
        $review = new \App\Application\Inventory\ReviewOwnershipProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)));
        $account = '00000000-0000-0000-0000-000000000001';
        $proof = '00000000-0000-0000-0000-000000000030';
        $audit = '00000000-0000-0000-0000-000000000031';
        $now = new \DateTimeImmutable('2026-10-11Z');
        self::assertFalse($review->reject($account, $proof, 1, 'Untrusted reviewer', $audit, $now));
        self::assertFalse($review->reject('00000000-0000-0000-0000-000000000002', $proof, 1, 'Own upload does not grant moderator authority', $audit, $now));
        $this->database->executeStatement("UPDATE accounts SET roles='[\"admin\"]',verified_at=NULL WHERE id=?", [$account]);
        self::assertFalse($review->reject($account, $proof, 1, 'Unverified reviewer', $audit, $now));
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z',lifecycle_state='suspended' WHERE id=?", [$account]);
        self::assertFalse($review->reject($account, $proof, 1, 'Suspended reviewer', $audit, $now));
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='active',roles='[\"super_admin\"]' WHERE id=?", [$account]);
        self::assertFalse($review->reject($account, $proof, 2, 'Stale review', $audit, $now));
        self::assertFalse($review->reject($account, '00000000-0000-0000-0000-000000000099', 1, 'Missing review', $audit, $now));
        self::assertFalse($review->reject($account, '00000000-0000-0000-0000-000000000008', 1, 'Already approved', $audit, $now));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertTrue($review->reject($account, $proof, 1, 'Document not issued for this race', $audit, $now));
        self::assertFalse($review->reject($account, $proof, 1, 'Repeated review', '00000000-0000-0000-0000-000000000032', $now));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertSame('approved', $this->database->fetchOne("SELECT decision FROM ownership_proofs WHERE id='00000000-0000-0000-0000-000000000008'"));
    }

    public function testCorruptPersistedReviewerRoleCannotRejectPendingProof(): void
    {
        $account = '00000000-0000-0000-0000-000000000001';
        $proof = '00000000-0000-0000-0000-000000000030';
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z',roles='[null]' WHERE id=?", [$account]);
        $this->database->executeStatement("INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,source_kind,private_object_id,submitted_at) VALUES (?, '00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000004','00999','document','00000000-0000-0000-0000-000000000007','2026-10-11Z')", [$proof]);
        $review = new \App\Application\Inventory\ReviewOwnershipProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)));
        try {
            $review->reject($account, $proof, 1, 'Malformed roles must not authorize', '00000000-0000-0000-0000-000000000031', new \DateTimeImmutable('2026-10-11Z'));
            self::fail('Malformed reviewer role must fail closed.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame('Invalid stored reviewer role.', $failure->getMessage());
            self::assertNull($this->database->fetchOne('SELECT decision FROM ownership_proofs WHERE id=?', [$proof]));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        }
    }

    public function testDocumentRejectionAuditFailureRollsBackAndBlankReasonIsInvalid(): void
    {
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z',roles='[\"admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
        $this->database->executeStatement("INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,source_kind,private_object_id,submitted_at) VALUES ('00000000-0000-0000-0000-000000000030','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000004','00999','document','00000000-0000-0000-0000-000000000007','2026-10-11Z')");
        $review = new \App\Application\Inventory\ReviewOwnershipProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)));
        $this->database->executeStatement('ALTER TABLE administrative_audit ADD CONSTRAINT reject_document_audit CHECK(false)');
        try {
            $review->reject('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030', 1, 'Document not issued for this race', '00000000-0000-0000-0000-000000000031', new \DateTimeImmutable('2026-10-11Z'));
            self::fail('Audit failure must roll back the review.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertSame(['decision' => null, 'reviewed_version' => null, 'reviewer_id' => null], $this->database->fetchAssociative("SELECT decision,reviewed_version,reviewer_id FROM ownership_proofs WHERE id='00000000-0000-0000-0000-000000000030'"));
        }
        try {
            $review->reject('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030', 1, '   ', '00000000-0000-0000-0000-000000000031', new \DateTimeImmutable('2026-10-11Z'));
            self::fail('A private rejection reason is required.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        }
    }

    public function testDocumentUploadQuarantinesRealBytesAndPersistsOnlyPendingProof(): void
    {
        $directory = sys_get_temp_dir().'/proof_storage_'.bin2hex(random_bytes(6));
        $source = tempnam(sys_get_temp_dir(), 'proof_source_');
        self::assertIsString($source);
        file_put_contents($source, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
        $storage = new \App\Infrastructure\Inventory\LocalProofQuarantine($directory, ['application/pdf'], 100);
        $upload = new \App\Application\Inventory\UploadDocumentProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)), $storage);
        try {
            self::assertTrue($upload->execute('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000004', '00999', $source, 'application/pdf', '00000000-0000-0000-0000-000000000030', '00000000-0000-0000-0000-000000000031', '00000000-0000-0000-0000-000000000032', new \DateTimeImmutable('2026-10-11Z')));
            self::assertSame(['decision' => null, 'reviewed_version' => null, 'private_object_id' => '00000000-0000-0000-0000-000000000031', 'proposed_bib_number' => '00999'], $this->database->fetchAssociative("SELECT decision,reviewed_version,private_object_id,proposed_bib_number FROM ownership_proofs WHERE id='00000000-0000-0000-0000-000000000030'"));
            self::assertSame(['mime_type' => 'application/pdf', 'size_bytes' => 35, 'checksum' => 'a580a4a92ef8d5c28ba07d43410bd8bdd0ea470461593dc7e5fbb354174098b9', 'uploaded_by' => '00000000-0000-0000-0000-000000000002'], $this->database->fetchAssociative("SELECT mime_type,size_bytes,checksum,uploaded_by FROM private_objects WHERE id='00000000-0000-0000-0000-000000000031'"));
            $files = glob($directory.'/*');
            self::assertIsArray($files);
            self::assertCount(1, $files);
            self::assertSame("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n", file_get_contents($files[0]));
            self::assertSame('ownership_proof.document_submitted', $this->database->fetchOne('SELECT action FROM administrative_audit'));
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM resale_listings'));
            $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z',roles='[\"admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
            $download = new \App\Application\Inventory\DownloadOwnershipProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)), $storage);
            $result = $download->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030');
            self::assertNotNull($result);
            self::assertSame("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n", $result->contents);
            self::assertSame('00000000-0000-0000-0000-000000000030.blob', $result->filename);
            self::assertNull($download->execute('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000030'));
            foreach (['verified_at=NULL', "lifecycle_state='suspended'", "roles='[null]'", "roles='[123]'", "roles='[{\"role\":\"admin\"}]'"] as $change) {
                $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z',lifecycle_state='active',roles='[\"admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
                $this->database->executeStatement('UPDATE accounts SET '.$change." WHERE id='00000000-0000-0000-0000-000000000001'");
                try {
                    self::assertNull($download->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030'));
                } catch (\UnexpectedValueException $invalidRoles) {
                    self::assertSame('Invalid stored reviewer role.', $invalidRoles->getMessage());
                }
            }
            $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z',lifecycle_state='active',roles='[\"super_admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
            self::assertNotNull($download->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030'));
            self::assertNull($download->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000099'));
            $revokingReader = new class($storage, $this->database) implements \App\Application\Inventory\PrivateProofReader {
                public function __construct(private \App\Application\Inventory\PrivateProofReader $reader, private \Doctrine\DBAL\Connection $database)
                {
                }

                public function read(\App\Application\Inventory\StoredProof $proof): string
                {
                    $contents = $this->reader->read($proof);
                    $this->database->executeStatement("UPDATE accounts SET lifecycle_state='suspended' WHERE id='00000000-0000-0000-0000-000000000001'");

                    return $contents;
                }
            };
            $revokedDownload = new \App\Application\Inventory\DownloadOwnershipProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)), $revokingReader);
            self::assertNull($revokedDownload->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030'));
            $this->database->executeStatement("UPDATE accounts SET lifecycle_state='active' WHERE id='00000000-0000-0000-0000-000000000001'");
            $this->database->executeStatement("UPDATE private_objects SET purged_at='2026-10-11Z' WHERE id='00000000-0000-0000-0000-000000000031'");
            self::assertNull($download->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030'));
            $this->database->executeStatement("UPDATE private_objects SET purged_at=NULL WHERE id='00000000-0000-0000-0000-000000000031'");
            file_put_contents($files[0], 'tampered private bytes');
            try {
                $download->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030');
                self::fail('Tampered stored proof must fail closed.');
            } catch (\RuntimeException $integrityFailure) {
                self::assertSame('Private proof object integrity check failed.', $integrityFailure->getMessage());
            }
            file_put_contents($files[0], "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
            try {
                $upload->execute('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000004', '00998', $source, 'application/pdf', '00000000-0000-0000-0000-000000000033', '00000000-0000-0000-0000-000000000034', '00000000-0000-0000-0000-000000000032', new \DateTimeImmutable('2026-10-11Z'));
                self::fail('Duplicate audit must roll back submission.');
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM private_objects WHERE id='00000000-0000-0000-0000-000000000034'"));
                self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM ownership_proofs WHERE id='00000000-0000-0000-0000-000000000033'"));
                self::assertCount(1, glob($directory.'/*') ?: []);
            }
            $revokingStorage = new class($storage, $this->database) implements \App\Application\Inventory\PrivateProofStorage {
                public function __construct(private \App\Application\Inventory\PrivateProofStorage $storage, private \Doctrine\DBAL\Connection $database)
                {
                }

                public function quarantine(string $objectId, string $temporaryPath, string $declaredMimeType): \App\Application\Inventory\QuarantinedProof
                {
                    $object = $this->storage->quarantine($objectId, $temporaryPath, $declaredMimeType);
                    $this->database->executeStatement("UPDATE accounts SET lifecycle_state='suspended' WHERE id='00000000-0000-0000-0000-000000000002'");

                    return $object;
                }

                public function discard(\App\Application\Inventory\QuarantinedProof $proof): void
                {
                    $this->storage->discard($proof);
                }
            };
            $revokedUpload = new \App\Application\Inventory\UploadDocumentProof(new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)), $revokingStorage);
            self::assertFalse($revokedUpload->execute('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000004', '00998', $source, 'application/pdf', '00000000-0000-0000-0000-000000000033', '00000000-0000-0000-0000-000000000034', '00000000-0000-0000-0000-000000000035', new \DateTimeImmutable('2026-10-11Z')));
            self::assertCount(1, glob($directory.'/*') ?: []);
            self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM private_objects WHERE id='00000000-0000-0000-0000-000000000034'"));
            self::assertFalse($upload->execute('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000004', '00998', '/source-must-not-be-read', 'application/pdf', '00000000-0000-0000-0000-000000000033', '00000000-0000-0000-0000-000000000034', '00000000-0000-0000-0000-000000000035', new \DateTimeImmutable('2026-10-11Z')));
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            unlink($source);
        }
    }

    public function testImportedEntitlementIsUnclaimedAndDuplicatesDoNotChangeOriginalHolder(): void
    {
        $store = new DbalEntitlementStore($this->database);
        self::assertTrue($store->recordImported('00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000019', '00000000-0000-0000-0000-000000000020', new \App\Domain\Inventory\BibNumber('00123'), 'Original Runner', 'buyer@example.test'));
        self::assertFalse($store->recordImported('00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000019', '00000000-0000-0000-0000-000000000020', new \App\Domain\Inventory\BibNumber('00123'), 'Wrong Update', 'wrong@example.test'));
        $row = $this->database->fetchAssociative("SELECT bib_number,current_owner_account_id,original_holder_name FROM bib_entitlements WHERE id='00000000-0000-0000-0000-000000000021'");
        self::assertNotFalse($row);
        self::assertSame('00123', $row['bib_number']);
        self::assertNull($row['current_owner_account_id']);
        self::assertSame('Original Runner', $row['original_holder_name']);
        self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM accounts'));
    }

    public function testClaimRequiresVerifiedMatchingHolderAndCreatesOneImmutableTransition(): void
    {
        $store = new DbalEntitlementStore($this->database);
        $store->recordImported('00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000019', '00000000-0000-0000-0000-000000000020', new \App\Domain\Inventory\BibNumber('00123'), 'Original Runner', 'buyer@example.test');
        $this->database->executeStatement("INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,entitlement_id,source_kind,submitted_at,decision,reviewed_version,reviewer_id,reviewed_at) VALUES ('00000000-0000-0000-0000-000000000022','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000019','00123','00000000-0000-0000-0000-000000000021','import','2026-10-08Z','approved',1,'00000000-0000-0000-0000-000000000001','2026-10-08Z')");
        $now = new \DateTimeImmutable('2026-10-08T12:00:00Z');
        self::assertFalse((new \App\Application\Inventory\ClaimEntitlement($store))->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', $now));
        self::assertTrue((new \App\Application\Inventory\ClaimEntitlement($store))->execute('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', $now));
        self::assertFalse((new \App\Application\Inventory\ClaimEntitlement($store))->execute('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000024', $now));
        $row = $this->database->fetchAssociative("SELECT current_owner_account_id,version FROM bib_entitlements WHERE id='00000000-0000-0000-0000-000000000021'");
        self::assertNotFalse($row);
        self::assertSame('00000000-0000-0000-0000-000000000002', $row['current_owner_account_id']);
        self::assertSame(2, $row['version']);
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM ownership_transitions'));
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->database->executeStatement("DELETE FROM ownership_transitions WHERE id='00000000-0000-0000-0000-000000000023'");
    }

    public function testConcurrentClaimsForTwoBibsInSameRaceHaveOneWinner(): void
    {
        $store = new DbalEntitlementStore($this->database);
        foreach ([['21', '22', '00123'], ['25', '26', '00456']] as [$entry, $proof, $bib]) {
            $entryId = '00000000-0000-0000-0000-0000000000'.$entry;
            $proofId = '00000000-0000-0000-0000-0000000000'.$proof;
            $store->recordImported($entryId, '00000000-0000-0000-0000-000000000019', '00000000-0000-0000-0000-000000000020', new \App\Domain\Inventory\BibNumber($bib), 'Runner', 'buyer@example.test');
            $this->database->executeStatement("INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,entitlement_id,source_kind,submitted_at,decision,reviewed_version,reviewer_id,reviewed_at) VALUES (?,'00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000019',?,?,'import','2026-10-08Z','approved',1,'00000000-0000-0000-0000-000000000001','2026-10-08Z')", [$proofId, $bib, $entryId]);
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';
$database = \Doctrine\DBAL\DriverManager::getConnection(json_decode(getenv('CLAIM_TEST_PARAMS'), true, 512, JSON_THROW_ON_ERROR));
$database->executeQuery("SELECT set_config('application_name', ?, false)", [getenv('CLAIM_TEST_NAME')]);
$store = new \App\Infrastructure\Inventory\DbalEntitlementStore($database);
echo $store->claim('00000000-0000-0000-0000-000000000002', $argv[1], $argv[2], $argv[3], new \DateTimeImmutable('2026-10-08T12:00:00Z')) ? 'won' : 'denied';
CODE;
        $name = 'claim_'.bin2hex(random_bytes(6));
        $environment = ['CLAIM_TEST_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR), 'CLAIM_TEST_NAME' => $name];
        $first = new \Symfony\Component\Process\Process(['php', '-r', $code, '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023'], dirname(__DIR__, 2), $environment);
        $second = new \Symfony\Component\Process\Process(['php', '-r', $code, '00000000-0000-0000-0000-000000000025', '00000000-0000-0000-0000-000000000026', '00000000-0000-0000-0000-000000000027'], dirname(__DIR__, 2), $environment);
        $this->database->beginTransaction();
        $this->database->executeQuery("SELECT id FROM races WHERE id='00000000-0000-0000-0000-000000000019' FOR UPDATE");
        try {
            $first->start();
            $second->start();
            $deadline = microtime(true) + 5;
            do {
                $this->database->executeQuery('SELECT pg_stat_clear_snapshot()');
                $blocked = $this->database->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$name]);
                if (2 === $blocked) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both claimants must reach the shared race lock before release.');
            $this->database->commit();
            self::assertSame(0, $first->wait());
            self::assertSame(0, $second->wait());
            $results = [$first->getOutput(), $second->getOutput()];
            sort($results);
            self::assertSame(['denied', 'won'], $results);
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM ownership_transitions'));
            self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM bib_entitlements WHERE race_id='00000000-0000-0000-0000-000000000019' AND current_owner_account_id IS NOT NULL"));
        } finally {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
            $first->stop();
            $second->stop();
        }
    }

    public function testMissingOrUnapprovedClaimDataCannotChangeOwnership(): void
    {
        $store = new DbalEntitlementStore($this->database);
        $now = new \DateTimeImmutable('2026-10-08T12:00:00Z');
        self::assertFalse($store->claim('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000099', '00000000-0000-0000-0000-000000000098', '00000000-0000-0000-0000-000000000097', $now));
        $store->recordImported('00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000019', '00000000-0000-0000-0000-000000000020', new \App\Domain\Inventory\BibNumber('00123'), 'Original Runner', 'buyer@example.test');
        self::assertFalse($store->claim('00000000-0000-0000-0000-000000000099', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000098', '00000000-0000-0000-0000-000000000097', $now));
        self::assertFalse($store->claim('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', '00000000-0000-0000-0000-000000000098', '00000000-0000-0000-0000-000000000097', $now));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM ownership_transitions'));
        self::assertNull($this->database->fetchOne("SELECT current_owner_account_id FROM bib_entitlements WHERE id='00000000-0000-0000-0000-000000000021'"));
    }
}
