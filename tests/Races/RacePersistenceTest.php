<?php

declare(strict_types=1);

namespace App\Tests\Races;

use App\Domain\Races\Distance;
use App\Domain\Races\Race;
use App\Domain\Races\RaceId;
use App\Domain\Races\RaceLocation;
use App\Infrastructure\Races\DbalRaceRepository;
use App\Tests\Support\IsolatedDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RacePersistenceTest extends KernelTestCase
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

    public function testRaceRoundTripsAllFieldsWithAndWithoutOrganization(): void
    {
        $repository = new DbalRaceRepository($this->database);
        $race = $this->race();
        $repository->save($race);
        $loaded = $repository->get($race->id);
        self::assertEquals($race, $loaded);
        self::assertNull($repository->get(new RaceId('00000000-0000-0000-0000-000000000099')));
        $this->database->insert('organizations', ['id' => '00000000-0000-0000-0000-000000000003', 'name' => 'Organizer']);
        $schema = new \stdClass();
        $schema->type = 'object';
        $schema->properties = new \stdClass();
        $schema->required = [];
        $schema->additionalProperties = false;
        $participating = $this->race(
            id: '00000000-0000-0000-0000-000000000002',
            organizationId: new RaceId('00000000-0000-0000-0000-000000000003'), participation: 'participating',
            description: 'Race description', imageUrl: 'https://example.test/race.jpg', officialUrl: 'https://example.test/race',
            resaleDeadline: new \DateTimeImmutable('2027-03-30T15:00:00Z'), minPriceCents: 1000, maxPriceCents: 10000,
            registrationSchemaVersion: 1, registrationSchema: $schema,
            location: new RaceLocation('Paris', 'FR', 'Rue de Rivoli', 'Île-de-France', '00123', '48.856600', '2.352200'),
        );
        $repository->save($participating);
        self::assertEquals($participating, $repository->get($participating->id));
    }

    public function testUpdatesRequireTheCurrentVersion(): void
    {
        $repository = new DbalRaceRepository($this->database);
        $repository->save($this->race());
        $saved = $repository->save($this->race(name: 'Updated race'), 1);
        self::assertSame(2, $saved->version);
        self::assertSame('Updated race', $repository->get($saved->id)?->name);
        $this->expectException(\App\Application\Races\StaleRaceVersion::class);
        $repository->save($this->race(name: 'Stale edit'), 1);
    }

    public function testDiscoveryReturnsOnlyPublishedPublicRecordsWithoutRequiringOrganizer(): void
    {
        $repository = new DbalRaceRepository($this->database);
        $repository->save($this->race());
        $repository->save($this->race(id: '00000000-0000-0000-0000-000000000002', publicationState: 'draft'));
        $repository->save($this->race(id: '00000000-0000-0000-0000-000000000003', publicationState: 'archived'));
        $discovery = new \App\Application\Races\DiscoverRaces(new \App\Infrastructure\Races\DbalRaceDiscovery($this->database));
        $page = $discovery->search(new \App\Application\Races\RaceSearch());
        self::assertCount(1, $page->races);
        self::assertSame('00000000-0000-0000-0000-000000000001', $page->races[0]->id);
        self::assertSame('Paris Marathon', $page->races[0]->name);
        self::assertNull($page->races[0]->organizationName);
        self::assertNull($page->nextCursor);
        self::assertSame(['id', 'name', 'startsAt', 'timezone', 'sportCode', 'distanceMetres', 'city', 'countryCode', 'organizationId', 'organizationName', 'description', 'imageUrl', 'officialUrl', 'participation'], array_keys(get_object_vars($page->races[0])));
    }

    public function testDiscoveryBindsAllFiltersAndTreatsWildcardsAsLiteral(): void
    {
        $repository = new DbalRaceRepository($this->database);
        $repository->save($this->race(name: 'Paris 100%_race', startsAt: '2027-04-01T08:00:00Z', distance: 10000));
        $repository->save($this->race(id: '00000000-0000-0000-0000-000000000002', name: 'Paris Marathon', startsAt: '2028-04-01Z', sportCode: 'cycling', location: new RaceLocation('Lyon')));
        $discovery = new \App\Application\Races\DiscoverRaces(new \App\Infrastructure\Races\DbalRaceDiscovery($this->database));
        $page = $discovery->search(new \App\Application\Races\RaceSearch(name: '100%_', startsFrom: new \DateTimeImmutable('2027-01-01Z'), startsUntil: new \DateTimeImmutable('2027-12-31Z'), sportCode: 'running', city: 'paris', countryCode: 'FR', minDistanceMetres: 10000, maxDistanceMetres: 10000));
        self::assertCount(1, $page->races);
        self::assertSame('Paris 100%_race', $page->races[0]->name);
        self::assertCount(0, $discovery->search(new \App\Application\Races\RaceSearch(name: "' OR 1=1 --"))->races);
        self::assertCount(0, $discovery->search(new \App\Application\Races\RaceSearch(city: 'Lille'))->races);
        self::assertCount(0, $discovery->search(new \App\Application\Races\RaceSearch(countryCode: 'BE'))->races);
        self::assertCount(0, $discovery->search(new \App\Application\Races\RaceSearch(minDistanceMetres: 50000))->races);
        self::assertCount(0, $discovery->search(new \App\Application\Races\RaceSearch(maxDistanceMetres: 1))->races);
    }

    public function testCursorPaginationKeepsTiedDatesWithoutDuplicatesAndIsBounded(): void
    {
        $repository = new DbalRaceRepository($this->database);
        foreach (range(1, 21) as $number) {
            $repository->save($this->race(id: sprintf('00000000-0000-0000-0000-%012d', $number)));
        }
        $discovery = new \App\Application\Races\DiscoverRaces(new \App\Infrastructure\Races\DbalRaceDiscovery($this->database));
        $first = $discovery->search(new \App\Application\Races\RaceSearch());
        self::assertCount(20, $first->races);
        self::assertSame('00000000-0000-0000-0000-000000000020', $first->races[19]->id);
        self::assertNotNull($first->nextCursor);
        $second = $discovery->search(new \App\Application\Races\RaceSearch(cursor: \App\Application\Races\RaceCursor::fromToken($first->nextCursor)));
        self::assertCount(1, $second->races);
        self::assertSame('00000000-0000-0000-0000-000000000021', $second->races[0]->id);
        self::assertNull($second->nextCursor);
        self::assertCount(2, $discovery->search(new \App\Application\Races\RaceSearch(limit: 2))->races);
    }

    public function testPublicDetailReturnsPublishedRaceAndHidesArchivedRace(): void
    {
        $repository = new DbalRaceRepository($this->database);
        $repository->save($this->race());
        $repository->save($this->race(id: '00000000-0000-0000-0000-000000000002', publicationState: 'archived'));
        $detail = new \App\Application\Races\FindPublicRace(new \App\Infrastructure\Races\DbalRaceDiscovery($this->database));
        self::assertSame('Paris Marathon', $detail->get('00000000-0000-0000-0000-000000000001')?->name);
        self::assertNull($detail->get('00000000-0000-0000-0000-000000000002'));
        self::assertNull($detail->get('00000000-0000-0000-0000-000000000099'));
    }

    private function race(
        string $id = '00000000-0000-0000-0000-000000000001',
        ?RaceId $organizationId = null,
        string $participation = 'nonparticipating',
        ?string $description = null,
        ?string $imageUrl = null,
        ?string $officialUrl = null,
        ?\DateTimeImmutable $resaleDeadline = null,
        ?int $minPriceCents = null,
        ?int $maxPriceCents = null,
        ?int $registrationSchemaVersion = null,
        ?\stdClass $registrationSchema = null,
        ?RaceLocation $location = null,
        int $version = 1,
        string $name = 'Paris Marathon',
        string $publicationState = 'published',
        string $startsAt = '2027-04-01T10:00:00+02:00',
        string $sportCode = 'running',
        int $distance = 42195,
    ): Race {
        return new Race(new RaceId($id), $name, new \DateTimeImmutable($startsAt), 'Europe/Paris', $sportCode, new Distance($distance), $location ?? new RaceLocation('Paris'), new \DateTimeImmutable('2026-10-08Z'), new \DateTimeImmutable('2026-10-08Z'), $version, $organizationId, $publicationState, $participation, $description, $imageUrl, $officialUrl, $resaleDeadline, $minPriceCents, $maxPriceCents, $registrationSchemaVersion, $registrationSchema);
    }
}
