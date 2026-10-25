<?php

declare(strict_types=1);

namespace App\Tests\Imports;

use App\Application\Imports\ExecuteEligibilityImport;
use App\Application\Imports\PreviewEligibilityImport;
use App\Application\Organizations\OrganizationScope;
use App\Infrastructure\Imports\DbalEligibilityImportStore;
use App\Infrastructure\Operations\DbalAuditWriter;
use App\Infrastructure\Operations\DbalTransactionManager;
use App\Infrastructure\Organizations\DbalOrganizationStore;
use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\Process\Process;

final class ImportExecutionTest extends IsolatedWebTestCase
{
    public function testIncompletePreviewRollsBackImportWithoutCreatingEntitlementsOrAudit(): void
    {
        $store = new DbalEligibilityImportStore($this->database, new DbalAuditWriter($this->database));
        try {
            $this->database->transactional(fn () => $store->persist('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000070', [['row' => 2, 'status' => 'valid', 'bib' => '00123', 'email' => 'runner@example.test']], new \DateTimeImmutable('2026-10-13Z'), new \App\Domain\Imports\ImportMode('skip_invalid', [], null)));
            self::fail('Incomplete preview created an import.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame('Invalid preview row.', $failure->getMessage());
            self::assertFalse($this->database->isTransactionActive());
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM bib_entitlements'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->database->executeStatement("INSERT INTO accounts(id,terms_version,terms_accepted_at,verified_at) VALUES ('00000000-0000-0000-0000-000000000001','v1','2026-01-01Z','2026-01-01Z'),('00000000-0000-0000-0000-000000000002','v1','2026-01-01Z','2026-01-01Z')");
        $this->database->executeStatement("INSERT INTO organizations(id,name) VALUES ('00000000-0000-0000-0000-000000000003','Organizer')");
        $this->database->executeStatement("INSERT INTO organization_memberships(id,organization_id,account_id,role,joined_at) VALUES ('00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000003','00000000-0000-0000-0000-000000000001','owner','2026-01-01Z')");
        $this->database->executeStatement("UPDATE organizations SET approval_state='approved',approved_by='00000000-0000-0000-0000-000000000002',approved_at='2026-01-01Z'");
        $this->database->executeStatement("INSERT INTO races(id,organization_id,name,starts_at,timezone,sport_code,distance_metres,city,country_code,created_at,updated_at,participation) VALUES ('00000000-0000-0000-0000-000000000005','00000000-0000-0000-0000-000000000003','Import race','2027-04-03Z','Europe/Paris','running',10000,'Paris','FR','2026-01-01Z','2026-01-01Z','participating')");
    }

    /** @param list<string>|null $mutableFields */
    private function importer(?array $mutableFields = null): ExecuteEligibilityImport
    {
        return new ExecuteEligibilityImport(new PreviewEligibilityImport(['bib' => 'entry', 'email' => 'contact', 'name' => 'holder'], 1024, 10), new OrganizationScope(new DbalOrganizationStore($this->database)), new DbalEligibilityImportStore($this->database, new DbalAuditWriter($this->database)), new DbalTransactionManager($this->database), $mutableFields);
    }

    public function testAuthorizedImportPersistsUnclaimedEntriesAndImmutableRowOutcomes(): void
    {
        $result = $this->importer()->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', "entry,contact,holder\n00123,RUNNER@example.test,Runner\n00456,invalid,Wrong\n00123,other@example.test,Duplicate", new \DateTimeImmutable('2026-10-10T12:00:00Z'));
        self::assertSame([['row' => 2, 'status' => 'imported', 'bib' => '00123'], ['row' => 3, 'status' => 'invalid', 'reason' => 'email'], ['row' => 4, 'status' => 'duplicate', 'bib' => '00123']], $result);
        self::assertSame(['bib_number' => '00123', 'original_runner_email' => 'runner@example.test', 'original_holder_name' => 'Runner', 'current_owner_account_id' => null], $this->database->fetchAssociative('SELECT bib_number,original_runner_email,original_holder_name,current_owner_account_id FROM bib_entitlements'));
        self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM accounts'));
        self::assertSame('completed', $this->database->fetchOne('SELECT state FROM eligibility_imports'));
        self::assertSame('eligibility_import.completed', $this->database->fetchOne('SELECT action FROM administrative_audit'));
    }

    public function testDuplicateImportAndExecutionRetryPreserveOriginalHolderAndOutcomes(): void
    {
        $importer = $this->importer();
        $now = new \DateTimeImmutable('2026-10-10T12:00:00Z');
        $first = $importer->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', "entry,contact,holder\n00123,runner@example.test,Runner", $now);
        self::assertSame([['row' => 2, 'status' => 'duplicate', 'bib' => '00123']], $importer->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000007', "entry,contact,holder\n00123,changed@example.test,Changed", $now));
        self::assertSame($first, $importer->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', "entry,contact,holder\n00999,new@example.test,New", $now));
        self::assertSame('runner@example.test', $this->database->fetchOne('SELECT original_runner_email FROM bib_entitlements'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM bib_entitlements'));
        self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testNonmemberAndUnverifiedOrSuspendedOrganizerCannotImport(): void
    {
        $importer = $this->importer();
        $csv = "entry,contact,holder\n00123,runner@example.test,Runner";
        foreach (['outsider', 'unverified', 'suspended', 'nonparticipating'] as $scenario) {
            $account = '00000000-0000-0000-0000-000000000001';
            if ('outsider' === $scenario) {
                $account = '00000000-0000-0000-0000-000000000002';
            } elseif ('unverified' === $scenario) {
                $this->database->executeStatement('UPDATE accounts SET verified_at=NULL WHERE id=?', [$account]);
            } elseif ('suspended' === $scenario) {
                $this->database->executeStatement("UPDATE accounts SET verified_at='2026-01-01Z',lifecycle_state='suspended' WHERE id=?", [$account]);
            } else {
                $this->database->executeStatement("UPDATE accounts SET lifecycle_state='active' WHERE id=?", [$account]);
                $this->database->executeStatement("UPDATE races SET participation='nonparticipating'");
            }
            try {
                $importer->execute($account, '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', $csv, new \DateTimeImmutable('2026-10-10Z'));
                self::fail('Denied import: '.$scenario);
            } catch (\DomainException) {
                self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
                self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM bib_entitlements'));
            }
        }
    }

    public function testAuditFailureRollsBackEntireImportAndEntries(): void
    {
        $this->database->executeStatement('ALTER TABLE administrative_audit ADD CONSTRAINT reject_import_audit CHECK(false)');
        try {
            $this->importer()->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', "entry,contact,holder\n00123,runner@example.test,Runner", new \DateTimeImmutable('2026-10-10Z'));
            self::fail('Audit failure must not retain imported entries.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM bib_entitlements'));
        }
    }

    public function testExplicitAllowedUpdateChangesOnlySelectedUnlistedRegistrationField(): void
    {
        $now = new \DateTimeImmutable('2026-10-10Z');
        $this->importer()->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', "entry,contact,holder\n00123,runner@example.test,Original", $now);
        self::assertSame([['row' => 2, 'status' => 'updated', 'bib' => '00123']], $this->importer(['name'])->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000007', "entry,contact,holder\n00123,changed@example.test,Updated", $now, 'update', ['name']));
        self::assertSame(['original_holder_name' => 'Updated', 'original_runner_email' => 'runner@example.test', 'current_owner_account_id' => null, 'version' => 2], $this->database->fetchAssociative('SELECT original_holder_name,original_runner_email,current_owner_account_id,version FROM bib_entitlements'));
        self::assertSame('update', $this->database->fetchOne("SELECT mode FROM eligibility_imports WHERE id='00000000-0000-0000-0000-000000000007'"));
    }

    public function testUpdatingRegistrationInvalidatesPreviousImportedProofApproval(): void
    {
        $now = new \DateTimeImmutable('2026-10-10Z');
        $this->importer()->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', "entry,contact,holder\n00123,runner@example.test,Original", $now);
        $entitlement = $this->database->fetchOne('SELECT id FROM bib_entitlements');
        self::assertIsString($entitlement);
        $this->database->executeStatement("INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,entitlement_id,source_kind,submitted_at,decision,reviewed_version,reviewer_id,reviewed_at) VALUES ('00000000-0000-0000-0000-000000000008','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000005','00123',?,'import','2026-01-01Z','approved',1,'00000000-0000-0000-0000-000000000002','2026-01-01Z')", [$entitlement]);
        $this->importer(['name'])->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000007', "entry,contact,holder\n00123,runner@example.test,Updated", $now, 'update', ['name']);
        self::assertSame(['proof_version' => 2, 'decision' => null, 'reviewed_version' => null, 'reviewer_id' => null], $this->database->fetchAssociative('SELECT proof_version,decision,reviewed_version,reviewer_id FROM ownership_proofs'));
    }

    public function testOwnedAndPreviouslyListedEntriesCannotBeUpdated(): void
    {
        $now = new \DateTimeImmutable('2026-10-10Z');
        $this->importer()->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', "entry,contact,holder\n00123,runner@example.test,Original\n00456,other@example.test,Other", $now);
        $this->database->executeStatement("UPDATE bib_entitlements SET current_owner_account_id='00000000-0000-0000-0000-000000000002' WHERE bib_number='00123'");
        $entitlement = $this->database->fetchOne("SELECT id FROM bib_entitlements WHERE bib_number='00456'");
        $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,entitlement_id,seller_id,price_cents,state) VALUES('00000000-0000-0000-0000-000000000008','00000000-0000-0000-0000-000000000005',?,'00000000-0000-0000-0000-000000000001',10000,'draft')", [$entitlement]);
        self::assertSame([['row' => 2, 'status' => 'protected', 'bib' => '00123'], ['row' => 3, 'status' => 'protected', 'bib' => '00456']], $this->importer(['name', 'email'])->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000007', "entry,contact,holder\n00123,changed@example.test,Changed\n00456,changed@example.test,Changed", $now, 'update', ['name', 'email']));
        self::assertSame(['Original', 'Other'], $this->database->fetchFirstColumn('SELECT original_holder_name FROM bib_entitlements ORDER BY bib_number'));
        self::assertSame('00000000-0000-0000-0000-000000000002', $this->database->fetchOne("SELECT current_owner_account_id FROM bib_entitlements WHERE bib_number='00123'"));
        self::assertSame(1, $this->database->fetchOne('SELECT version FROM resale_listings'));
    }

    public function testPersistenceRequiresOwningTransactionBeforeWritingAnything(): void
    {
        $store = new DbalEligibilityImportStore($this->database, new DbalAuditWriter($this->database));
        try {
            $store->persist('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', [], new \DateTimeImmutable('2026-10-10Z'), new \App\Domain\Imports\ImportMode('skip_invalid', [], null));
            self::fail('Owning transaction is required.');
        } catch (\LogicException) {
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
        }
    }

    public function testConcurrentImportsCreateOneEntitlementAndPreserveFirstHolder(): void
    {
        $code = <<<'CODE'
require 'vendor/autoload.php';
$database = \Doctrine\DBAL\DriverManager::getConnection(json_decode(getenv('IMPORT_TEST_PARAMS'), true, 512, JSON_THROW_ON_ERROR));
$database->executeQuery("SELECT set_config('application_name', ?, false)", [getenv('IMPORT_TEST_NAME')]);
$importer = new \App\Application\Imports\ExecuteEligibilityImport(new \App\Application\Imports\PreviewEligibilityImport(['bib'=>'entry','email'=>'contact','name'=>'holder'],1024,10),new \App\Application\Organizations\OrganizationScope(new \App\Infrastructure\Organizations\DbalOrganizationStore($database)),new \App\Infrastructure\Imports\DbalEligibilityImportStore($database,new \App\Infrastructure\Operations\DbalAuditWriter($database)),new \App\Infrastructure\Operations\DbalTransactionManager($database));
$result = $importer->execute('00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000005',$argv[1],"entry,contact,holder\n00123,".$argv[2]."@example.test,".$argv[2],new \DateTimeImmutable('2026-10-10Z'));
echo $result[0]['status'];
CODE;
        $name = 'import_'.bin2hex(random_bytes(6));
        $environment = ['IMPORT_TEST_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR), 'IMPORT_TEST_NAME' => $name];
        $first = new Process(['php', '-r', $code, '00000000-0000-0000-0000-000000000006', 'first'], dirname(__DIR__, 2), $environment);
        $second = new Process(['php', '-r', $code, '00000000-0000-0000-0000-000000000007', 'second'], dirname(__DIR__, 2), $environment);
        $this->database->beginTransaction();
        $this->database->executeQuery("SELECT id FROM races WHERE id='00000000-0000-0000-0000-000000000005' FOR UPDATE");
        try {
            $first->start();
            $second->start();
            $deadline = microtime(true) + 10;
            do {
                $this->database->executeQuery('SELECT pg_stat_clear_snapshot()');
                $blocked = $this->database->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$name]);
                if (2 === $blocked) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both imports must reach the shared race lock before release.');
            $this->database->commit();
            self::assertSame(0, $first->wait());
            self::assertSame(0, $second->wait());
            $results = [$first->getOutput(), $second->getOutput()];
            sort($results);
            self::assertSame(['duplicate', 'imported'], $results);
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM bib_entitlements'));
            $holder = $this->database->fetchOne('SELECT original_holder_name FROM bib_entitlements');
            self::assertContains($holder, ['first', 'second']);
            self::assertSame($holder.'@example.test', $this->database->fetchOne('SELECT original_runner_email FROM bib_entitlements'));
            self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
        } finally {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
            $first->stop();
            $second->stop();
        }
    }

    public function testExistingImportIdentifierCannotBeTakenByAnotherAuthorizedMember(): void
    {
        $this->database->executeStatement("INSERT INTO organization_memberships(id,organization_id,account_id,role,joined_at) VALUES('00000000-0000-0000-0000-000000000009','00000000-0000-0000-0000-000000000003','00000000-0000-0000-0000-000000000002','manager','2026-01-01Z')");
        $csv = "entry,contact,holder\n00123,runner@example.test,Runner";
        $now = new \DateTimeImmutable('2026-10-10Z');
        $this->importer()->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', $csv, $now);
        try {
            $this->importer()->execute('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', $csv, $now);
            self::fail('Existing import identity must retain its original actor.');
        } catch (\DomainException) {
            self::assertSame('00000000-0000-0000-0000-000000000001', $this->database->fetchOne('SELECT actor_id FROM eligibility_imports'));
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedLegacyResults')]
    public function testMalformedLegacyImportResultsFailWithoutNewEntries(string $results): void
    {
        $this->database->executeStatement("INSERT INTO eligibility_imports(id,race_id,actor_id,mode,state,started_at,completed_at,row_results) VALUES('00000000-0000-0000-0000-000000000006','00000000-0000-0000-0000-000000000005','00000000-0000-0000-0000-000000000001','skip_invalid','completed','2026-01-01Z','2026-01-01Z',?)", [$results]);
        try {
            $this->importer()->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', "entry,contact,holder\n00123,runner@example.test,Runner", new \DateTimeImmutable('2026-10-10Z'));
            self::fail('Malformed legacy results must not be replayed.');
        } catch (\UnexpectedValueException) {
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM bib_entitlements'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        }
    }

    /** @return iterable<string,array{string}> */
    public static function malformedLegacyResults(): iterable
    {
        yield 'invalid row' => ['[1]'];
        yield 'unknown result status' => ['[{"row":2,"status":"unexpected"}]'];
        yield 'invalid index' => ['[{"row":"2","status":"imported"}]'];
        yield 'invalid bib' => ['[{"row":2,"status":"imported","bib":123}]'];
    }

    public function testOrganizerImportCannotOverwriteDocumentBasedOwnershipEvidence(): void
    {
        $this->database->executeStatement("INSERT INTO bib_entitlements(id,race_id,bib_number,source_kind,original_holder_name,original_runner_email) VALUES('00000000-0000-0000-0000-000000000008','00000000-0000-0000-0000-000000000005','00123','document','Document holder','document@example.test')");
        self::assertSame([['row' => 2, 'status' => 'protected', 'bib' => '00123']], $this->importer(['name', 'email'])->execute('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000006', "entry,contact,holder\n00123,changed@example.test,Changed", new \DateTimeImmutable('2026-10-10Z'), 'update', ['name', 'email']));
        self::assertSame('Document holder', $this->database->fetchOne('SELECT original_holder_name FROM bib_entitlements'));
    }
}
