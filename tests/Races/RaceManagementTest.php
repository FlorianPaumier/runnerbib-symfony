<?php

declare(strict_types=1);

namespace App\Tests\Races;

use App\Tests\Support\IsolatedDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RaceManagementTest extends KernelTestCase
{
    private Connection $admin;
    private Connection $database;
    private string $databaseName;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = self::getContainer()->get(Connection::class);
        $this->databaseName = 'race_test_'.bin2hex(random_bytes(6));
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

    public function testAdministratorCreatesRaceWithoutOrganizerAndAuditsInOneTransaction(): void
    {
        $this->database->insert('accounts', ['id' => '00000000-0000-0000-0000-000000000001', 'roles' => '["admin"]', 'verified_at' => '2026-10-08Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-08Z']);
        $races = $this->management();
        $races->create('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Paris race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris'), '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $race = (new \App\Infrastructure\Races\DbalRaceRepository($this->database))->get(new \App\Domain\Races\RaceId('00000000-0000-0000-0000-000000000010'));
        self::assertNotNull($race);
        self::assertSame('draft', $race->publicationState);
        self::assertNull($race->organizationId);
        self::assertSame(1, $race->version);
        $this->assertRaceAudit('00000000-0000-0000-0000-000000000020', 'race.created', ['roles' => ['admin'], 'organization_id' => null]);
    }

    public function testRunnerCannotCreateAnUnmanagedRace(): void
    {
        $this->database->insert('accounts', ['id' => '00000000-0000-0000-0000-000000000001', 'roles' => '["runner"]', 'verified_at' => '2026-10-08Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-08Z']);
        $this->expectException(\DomainException::class);
        $this->management()->create('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Paris race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris'), '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
    }

    public function testOrganizationMemberCreatesRaceOnlyWithinApprovedOrganization(): void
    {
        $this->seedOrganization();
        $definition = new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Paris race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris', organizationId: '00000000-0000-0000-0000-000000000003', participation: 'participating');
        $this->management()->create('00000000-0000-0000-0000-000000000001', $definition, '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        self::assertSame('participating', (new \App\Infrastructure\Races\DbalRaceRepository($this->database))->get(new \App\Domain\Races\RaceId($definition->id))?->participation);
        $this->expectException(\DomainException::class);
        $this->management()->create('00000000-0000-0000-0000-000000000002', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000011', 'Other race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris', organizationId: '00000000-0000-0000-0000-000000000003'), '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-08Z'));
    }

    public function testScopedMetadataUpdateRejectsStaleVersionAndAnotherActor(): void
    {
        $this->seedOrganization();
        $races = $this->management();
        $initial = new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Paris race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris', organizationId: '00000000-0000-0000-0000-000000000003', participation: 'participating');
        $races->create('00000000-0000-0000-0000-000000000001', $initial, '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $updated = new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Updated race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris', organizationId: '00000000-0000-0000-0000-000000000003', publicationState: 'published', participation: 'participating');
        $races->update('00000000-0000-0000-0000-000000000001', $updated, 1, '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-09Z'));
        $race = (new \App\Infrastructure\Races\DbalRaceRepository($this->database))->get(new \App\Domain\Races\RaceId($updated->id));
        self::assertNotNull($race);
        self::assertSame('Updated race', $race->name);
        $this->assertRaceAudit('00000000-0000-0000-0000-000000000021', 'race.updated', ['roles' => ['runner'], 'organization_id' => '00000000-0000-0000-0000-000000000003', 'membership' => 'owner']);
        self::assertSame(2, $race->version);
        self::assertSame('participating', $race->participation);
        $this->expectException(\App\Application\Races\StaleRaceVersion::class);
        $races->update('00000000-0000-0000-0000-000000000001', $updated, 1, '00000000-0000-0000-0000-000000000022', new \DateTimeImmutable('2026-10-09Z'));
    }

    public function testArchivePreservesParticipationAndRaceIdentity(): void
    {
        $this->seedOrganization();
        $races = $this->management();
        $races->create('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Paris race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris', organizationId: '00000000-0000-0000-0000-000000000003', publicationState: 'published', participation: 'participating'), '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $races->archive('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', 1, '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-09Z'));
        $race = (new \App\Infrastructure\Races\DbalRaceRepository($this->database))->get(new \App\Domain\Races\RaceId('00000000-0000-0000-0000-000000000010'));
        self::assertNotNull($race);
        self::assertSame('archived', $race->publicationState);
        $this->assertRaceAudit('00000000-0000-0000-0000-000000000021', 'race.archived', ['roles' => ['runner'], 'organization_id' => '00000000-0000-0000-0000-000000000003', 'membership' => 'owner']);
        self::assertSame('participating', $race->participation);
        self::assertSame('00000000-0000-0000-0000-000000000003', $race->organizationId?->value);
        self::assertSame(2, $race->version);
    }

    public function testOrdinaryEditCannotMoveRaceOutOfOrganizationScope(): void
    {
        $this->seedOrganization();
        $races = $this->management();
        $races->create('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Paris race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris', organizationId: '00000000-0000-0000-0000-000000000003'), '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $this->expectException(\DomainException::class);
        $races->update('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Moved race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris'), 1, '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-09Z'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingRaceCommands')]
    public function testMissingRaceCannotBeMutated(bool $archive): void
    {
        $this->expectException(\DomainException::class);
        if ($archive) {
            $this->management()->archive('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', 1, '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        } else {
            $this->management()->update('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Paris race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris'), 1, '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        }
    }

    /** @return iterable<string,array{bool}> */
    public static function missingRaceCommands(): iterable
    {
        yield 'archive' => [true];
        yield 'update' => [false];
    }

    public function testOtherAccountCannotEditOrArchiveOrganizationRace(): void
    {
        $this->seedOrganization();
        $races = $this->management();
        $races->create('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Paris race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris', organizationId: '00000000-0000-0000-0000-000000000003'), '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $this->expectException(\DomainException::class);
        $races->archive('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000010', 1, '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-09Z'));
    }

    public function testRaceCreationRollsBackWhenItsAuditFails(): void
    {
        $this->database->insert('accounts', ['id' => '00000000-0000-0000-0000-000000000001', 'roles' => '["admin"]', 'verified_at' => '2026-10-08Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-08Z']);
        $this->database->executeStatement('DROP TABLE administrative_audit');
        try {
            $this->management()->create('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Paris race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris'), '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
            self::fail('Race committed without its audit.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertNull((new \App\Infrastructure\Races\DbalRaceRepository($this->database))->get(new \App\Domain\Races\RaceId('00000000-0000-0000-0000-000000000010')));
        }
    }

    public function testOrdinaryMetadataEditCannotChangeParticipationPolicy(): void
    {
        $this->seedOrganization();
        $races = $this->management();
        $races->create('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Paris race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris', organizationId: '00000000-0000-0000-0000-000000000003'), '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
        $this->expectException(\DomainException::class);
        $races->update('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Same race', new \DateTimeImmutable('2027-04-01Z'), 'Europe/Paris', 'running', 10000, 'Paris', organizationId: '00000000-0000-0000-0000-000000000003', participation: 'participating'), 1, '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-09Z'));
    }

    /** @param array<string,mixed> $authority */
    private function assertRaceAudit(string $id, string $action, array $authority): void
    {
        $row = $this->database->fetchAssociative('SELECT action,subject_kind,authority_snapshot FROM administrative_audit WHERE id=?', [$id]);
        self::assertIsArray($row);
        self::assertSame($action, $row['action']);
        self::assertSame('race', $row['subject_kind']);
        self::assertIsString($row['authority_snapshot']);
        $facts = json_decode($row['authority_snapshot'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($facts);
        foreach ($authority as $key => $expected) {
            self::assertArrayHasKey($key, $facts);
            self::assertSame($expected, $facts[$key]);
        }
    }

    public function testARejectedOrganizationCannotCreateAnotherRace(): void
    {
        $this->seedOrganization();
        $this->database->executeStatement("UPDATE organizations SET approval_state='rejected'");
        $this->expectException(\DomainException::class);
        $this->management()->create('00000000-0000-0000-0000-000000000001', new \App\Application\Races\RaceDefinition('00000000-0000-0000-0000-000000000010', 'Race', new \DateTimeImmutable('2027-04-01Z'), 'UTC', 'running', 10000, 'Paris', organizationId: '00000000-0000-0000-0000-000000000003', participation: 'participating'), '00000000-0000-0000-0000-000000000020', new \DateTimeImmutable('2026-10-08Z'));
    }

    private function seedOrganization(): void
    {
        foreach ([1 => '["runner"]', 2 => '["admin"]'] as $number => $roles) {
            $this->database->insert('accounts', ['id' => sprintf('00000000-0000-0000-0000-%012d', $number), 'roles' => $roles, 'verified_at' => '2026-10-08Z', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-08Z']);
        }
        $organizations = new \App\Application\Organizations\ManageOrganizations(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
        $organizations->request('00000000-0000-0000-0000-000000000003', 'Club', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000004', new \DateTimeImmutable('2026-10-08Z'));
        $organizations->approve('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003', new \DateTimeImmutable('2026-10-08Z'));
    }

    private function management(): \App\Application\Races\ManageRaces
    {
        return new \App\Application\Races\ManageRaces(new \App\Infrastructure\Races\DbalRaceRepository($this->database), new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database), new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
    }
}
