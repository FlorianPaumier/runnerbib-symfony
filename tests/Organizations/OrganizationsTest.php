<?php

declare(strict_types=1);

namespace App\Tests\Organizations;

use App\Tests\Support\IsolatedDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrganizationsTest extends KernelTestCase
{
    private Connection $admin;
    private Connection $database;
    private string $databaseName;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = self::getContainer()->get(Connection::class);
        $this->databaseName = 'organization_test_'.bin2hex(random_bytes(6));
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($url);
        IsolatedDatabase::create($this->admin, $this->databaseName, dirname(__DIR__, 2), $url);
        $parameters = $this->admin->getParams();
        $parameters['dbname'] = $this->databaseName;
        $this->database = DriverManager::getConnection($parameters);
        $databaseUrl = preg_replace('~/[^/?]+(?=\?|$)~', '/'.$this->databaseName, $url);
        self::assertIsString($databaseUrl);
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->admin->executeStatement('DROP DATABASE '.$this->databaseName.' WITH (FORCE)');
        parent::tearDown();
    }

    public function testOwnerRequestsOrganizationAndAdministratorApprovesIt(): void
    {
        $this->seedAccounts();
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        self::assertSame('pending', $organizations->get('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010')->approvalState);
        $organizations->approve('00000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000010', new \DateTimeImmutable('2026-10-08Z'));
        $organization = $organizations->get('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010');
        self::assertSame('approved', $organization->approvalState);
        self::assertSame('00000000-0000-0000-0000-000000000001', $organization->ownerAccountId);
    }

    public function testMalformedStoredRolesCannotGrantAuthority(): void
    {
        $this->seedAccounts();
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $this->database->executeStatement("UPDATE accounts SET roles='[\"runner\",42]' WHERE id='00000000-0000-0000-0000-000000000001'");
        try {
            $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
            self::fail('Malformed stored roles must not be trusted.');
        } catch (\UnexpectedValueException $error) {
            self::assertSame('Invalid stored account role.', $error->getMessage());
        }
    }

    public function testRunnerCannotApproveOrganization(): void
    {
        $this->seedAccounts();
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $this->expectException(\DomainException::class);
        $organizations->approve('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000010', new \DateTimeImmutable('2026-10-08Z'));
    }

    public function testAnotherAccountCannotReadPrivateOrganization(): void
    {
        $this->seedAccounts();
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $this->expectException(\DomainException::class);
        $organizations->get('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000010');
    }

    public function testOwnerAddsManagerButManagerCannotManageMembership(): void
    {
        $this->seedAccounts();
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $organizations->approve('00000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000010', new \DateTimeImmutable('2026-10-08Z'));
        $organizations->addManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-08Z'));
        self::assertSame('Paris Club', $organizations->get('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000010')->name);
        $this->expectException(\DomainException::class);
        $organizations->addManager('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000022', new \DateTimeImmutable('2026-10-08Z'));
    }

    public function testOwnerTransferPreservesMembershipHistoryAndChangesPermissions(): void
    {
        $this->seedAccounts();
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $now = new \DateTimeImmutable('2026-10-08Z');
        $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', $now);
        $organizations->approve('00000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000010', $now);
        $organizations->addManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', $now);
        $organizations->transferOwnership('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', $now);
        self::assertSame('00000000-0000-0000-0000-000000000002', $organizations->get('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010')->ownerAccountId);
        $history = $organizations->history('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000010');
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM administrative_audit WHERE action='organization.manager_added' AND authority_snapshot->>'organization_role'='owner'"));
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM administrative_audit WHERE action='organization.owner_transferred' AND actor_account_id='00000000-0000-0000-0000-000000000001'"));
        self::assertCount(4, $history);
        self::assertNotNull($history[0]->endedAt);
        self::assertNotNull($history[1]->endedAt);
        self::assertNull($history[2]->endedAt);
        self::assertSame('manager', $history[2]->role);
        self::assertSame('owner', $history[3]->role);
        $this->expectException(\DomainException::class);
        $organizations->addManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000024', $now);
    }

    public function testUnverifiedAccountCannotRequestOrganization(): void
    {
        $this->seedAccounts();
        $this->database->executeStatement("UPDATE accounts SET verified_at=NULL WHERE id='00000000-0000-0000-0000-000000000001'");
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $this->expectException(\DomainException::class);
        $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
    }

    public function testPendingOrganizationCannotAddManagers(): void
    {
        $this->seedAccounts();
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $now = new \DateTimeImmutable('2026-10-08Z');
        $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', $now);
        $this->expectException(\DomainException::class);
        $organizations->addManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', $now);
    }

    public function testOrganizationWithoutActiveOwnerCannotBeApproved(): void
    {
        $this->seedAccounts();
        $this->database->insert('organizations', ['id' => '00000000-0000-0000-0000-000000000010', 'name' => 'Ownerless']);
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $this->expectException(\DomainException::class);
        $organizations->approve('00000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000010', new \DateTimeImmutable('2026-10-08Z'));
    }

    public function testUnverifiedTargetCannotBecomeManager(): void
    {
        $organizations = $this->approvedOrganization();
        $this->database->executeStatement("UPDATE accounts SET verified_at=NULL WHERE id='00000000-0000-0000-0000-000000000002'");
        $this->expectException(\DomainException::class);
        $organizations->addManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-08Z'));
    }

    public function testOwnerCannotTransferToSelf(): void
    {
        $organizations = $this->approvedOrganization();
        $this->expectException(\DomainException::class);
        $organizations->transferOwnership('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', new \DateTimeImmutable('2026-10-08Z'));
    }

    public function testRaceResourceScopeRejectsAnotherOrganizationIdentifier(): void
    {
        $this->approvedOrganization();
        $this->database->executeStatement("INSERT INTO races(id,organization_id,name,starts_at,timezone,sport_code,distance_metres,city,country_code,created_at,updated_at) VALUES ('00000000-0000-0000-0000-000000000030','00000000-0000-0000-0000-000000000010','Race','2027-04-01Z','Europe/Paris','running',10000,'Paris','FR','2026-10-08Z','2026-10-08Z')");
        $scope = new \App\Application\Organizations\OrganizationScope(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database));
        self::assertSame('Paris Club', $scope->forRace('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000030')->name);
        $this->expectException(\DomainException::class);
        $scope->forRace('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000030');
    }

    public function testDashboardViewContainsCurrentMembershipPermissions(): void
    {
        $organizations = $this->approvedOrganization();
        $owner = $organizations->get('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010');
        self::assertTrue($owner->canManageMembership);
        self::assertSame('owner', $owner->memberRole);
        $organizations->addManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-08Z'));
        $manager = $organizations->get('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000010');
        self::assertFalse($manager->canManageMembership);
        self::assertSame('manager', $manager->memberRole);
    }

    public function testCurrentOrganizationIsDerivedFromActiveMembership(): void
    {
        $organizations = $this->approvedOrganization();
        self::assertSame('Paris Club', $organizations->mine('00000000-0000-0000-0000-000000000001')?->name);
        self::assertNull($organizations->mine('00000000-0000-0000-0000-000000000002'));
    }

    public function testMissingRaceCannotGrantOrganizationAccess(): void
    {
        $scope = new \App\Application\Organizations\OrganizationScope(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database));
        $this->expectException(\DomainException::class);
        $scope->forRace('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000099');
    }

    public function testDuplicateOrganizationRequestRollsBackAndPreservesMembership(): void
    {
        $organizations = $this->approvedOrganization();
        try {
            $organizations->request('00000000-0000-0000-0000-000000000011', 'Second club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-08Z'));
            self::fail('An account joined two active organizations.');
        } catch (\DomainException) {
            self::assertSame('Paris Club', $organizations->mine('00000000-0000-0000-0000-000000000001')?->name);
        }
        $this->expectException(\DomainException::class);
        (new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database))->get('00000000-0000-0000-0000-000000000011');
    }

    public function testOrganizationRequestRollsBackWhenAuditCannotPersist(): void
    {
        $this->seedAccounts();
        $this->database->executeStatement('DROP TABLE administrative_audit');
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        try {
            $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
            self::fail('Organization creation succeeded without its required audit.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertNull($organizations->mine('00000000-0000-0000-0000-000000000001'));
        }
    }

    public function testAdministratorSuspendsOrganizationAndDisablesManagerChanges(): void
    {
        $organizations = $this->approvedOrganization();
        $organizations->moderate('00000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000010', 'suspended', new \DateTimeImmutable('2026-10-09Z'));
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM administrative_audit WHERE action='organization.suspended' AND authority_snapshot->>'organization_role'='administrator'"));
        self::assertSame('suspended', $organizations->get('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010')->approvalState);
        $this->expectException(\DomainException::class);
        $organizations->addManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-09Z'));
    }

    public function testAdministratorRejectsPendingOrganization(): void
    {
        $this->seedAccounts();
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $organizations->moderate('00000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000010', 'rejected', new \DateTimeImmutable('2026-10-09Z'));
        self::assertSame('rejected', $organizations->get('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010')->approvalState);
    }

    public function testOwnerEndsManagerMembershipWithoutDeletingHistory(): void
    {
        $organizations = $this->approvedOrganization();
        $organizations->addManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-08Z'));
        $organizations->removeManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', new \DateTimeImmutable('2026-10-09Z'));
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM administrative_audit WHERE action='organization.manager_removed'"));
        self::assertNull($organizations->mine('00000000-0000-0000-0000-000000000002'));
        $history = $organizations->history('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010');
        self::assertCount(2, $history);
        self::assertNotNull($history[1]->endedAt);
    }

    public function testOwnerMembershipCannotBeRemovedAsManager(): void
    {
        $organizations = $this->approvedOrganization();
        $this->expectException(\DomainException::class);
        $organizations->removeManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000001', new \DateTimeImmutable('2026-10-09Z'));
    }

    public function testImportResourceUsesItsAuthoritativeRaceOrganization(): void
    {
        $this->approvedOrganization();
        $this->database->executeStatement("INSERT INTO races(id,organization_id,name,starts_at,timezone,sport_code,distance_metres,city,country_code,created_at,updated_at) VALUES ('00000000-0000-0000-0000-000000000030','00000000-0000-0000-0000-000000000010','Race','2027-04-01Z','Europe/Paris','running',10000,'Paris','FR','2026-10-08Z','2026-10-08Z')");
        $this->database->insert('eligibility_imports', ['id' => '00000000-0000-0000-0000-000000000040', 'race_id' => '00000000-0000-0000-0000-000000000030', 'actor_id' => '00000000-0000-0000-0000-000000000001', 'mode' => 'skip_invalid', 'state' => 'pending', 'started_at' => '2026-10-08Z']);
        $scope = new \App\Application\Organizations\OrganizationScope(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database));
        self::assertSame('Paris Club', $scope->forImport('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000040')->name);
        $this->expectException(\DomainException::class);
        $scope->forImport('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000040');
    }

    public function testMalformedModerationStateIsRejected(): void
    {
        $organizations = $this->approvedOrganization();
        $this->expectException(\InvalidArgumentException::class);
        $organizations->moderate('00000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000010', 'approved', new \DateTimeImmutable('2026-10-09Z'));
    }

    public function testMissingImportCannotGrantAccess(): void
    {
        $scope = new \App\Application\Organizations\OrganizationScope(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database));
        $this->expectException(\DomainException::class);
        $scope->forImport('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000099');
    }

    public function testConcurrentOwnerTransferWaitsForOrganizationLockAndPreservesOwner(): void
    {
        $organizations = $this->approvedOrganization();
        $organizations->addManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-08Z'));
        $competitor = DriverManager::getConnection($this->database->getParams());
        $competitor->executeStatement("SET lock_timeout='100ms'");
        $this->database->beginTransaction();
        $this->database->executeQuery("SELECT id FROM organizations WHERE id='00000000-0000-0000-0000-000000000010' FOR UPDATE");
        try {
            $competing = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($competitor), new \App\Infrastructure\Operations\DbalAuditWriter($competitor));
            try {
                $competing->transferOwnership('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000022', '00000000-0000-0000-0000-000000000023', new \DateTimeImmutable('2026-10-09Z'));
                self::fail('Concurrent transfer bypassed the organization lock.');
            } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
        } finally {
            $this->database->rollBack();
            $competitor->close();
        }
        self::assertSame('00000000-0000-0000-0000-000000000001', $organizations->mine('00000000-0000-0000-0000-000000000001')?->ownerAccountId);
        self::assertCount(2, $organizations->history('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010'));
    }

    public function testFailedTransferRollsBackBothMembershipTransitions(): void
    {
        $organizations = $this->approvedOrganization();
        $organizations->addManager('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-08Z'));
        try {
            $organizations->transferOwnership('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000020', '00000000-0000-0000-0000-000000000023', new \DateTimeImmutable('2026-10-09Z'));
            self::fail('Transfer accepted a conflicting membership identity.');
        } catch (\DomainException) {
            self::assertSame('00000000-0000-0000-0000-000000000001', $organizations->mine('00000000-0000-0000-0000-000000000001')?->ownerAccountId);
            self::assertCount(2, $organizations->history('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010'));
        }
    }

    public function testPrivateHistoryAndModerationRefuseUnauthorizedActors(): void
    {
        $organizations = $this->approvedOrganization();
        foreach (['history', 'moderate'] as $operation) {
            try {
                if ('history' === $operation) {
                    $organizations->history('00000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000010');
                } else {
                    $organizations->moderate('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', 'rejected', new \DateTimeImmutable('2026-10-09Z'));
                }
                self::fail('Private organization action accepted an unauthorized actor.');
            } catch (\DomainException) {
                self::assertSame('approved', $organizations->get('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010')->approvalState);
            }
        }
    }

    private function approvedOrganization(): \App\Application\Organizations\ManageOrganizations
    {
        $this->seedAccounts();
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $organizations->request('00000000-0000-0000-0000-000000000010', 'Paris Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $organizations->approve('00000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000010', new \DateTimeImmutable('2026-10-08Z'));

        return $organizations;
    }

    private function seedAccounts(): void
    {
        foreach ([1 => '["runner"]', 2 => '["runner"]', 3 => '["admin"]', 4 => '["runner"]'] as $number => $roles) {
            $this->database->insert('accounts', ['id' => sprintf('00000000-0000-0000-0000-%012d', $number), 'roles' => $roles, 'verified_at' => '2026-10-08Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-08Z']);
        }
    }
}
