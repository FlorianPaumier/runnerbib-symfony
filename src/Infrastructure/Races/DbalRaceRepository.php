<?php

declare(strict_types=1);

namespace App\Infrastructure\Races;

use App\Application\Races\RaceRepository;
use App\Application\Races\StaleRaceVersion;
use App\Domain\Races\Distance;
use App\Domain\Races\Race;
use App\Domain\Races\RaceId;
use App\Domain\Races\RaceLocation;
use Doctrine\DBAL\Connection;

final readonly class DbalRaceRepository implements RaceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(Race $race, ?int $expectedVersion = null): Race
    {
        $data = $this->data($race);
        if (null === $expectedVersion) {
            $this->connection->insert('races', $data);

            return $race;
        }
        $data['version'] = $expectedVersion + 1;
        unset($data['id'], $data['created_at']);
        $assignments = implode(', ', array_map(static fn (string $column): string => $column.' = :'.$column, array_keys($data)));
        $affected = $this->connection->executeStatement('UPDATE races SET '.$assignments.' WHERE id = :id AND version = :expected_version', $data + ['id' => $race->id->value, 'expected_version' => $expectedVersion]);
        if (1 !== $affected) {
            throw new StaleRaceVersion('Race changed or no longer exists.');
        }
        $saved = $this->get($race->id);
        assert($saved instanceof Race);

        return $saved;
    }

    public function get(RaceId $id): ?Race
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM races WHERE id = :id', ['id' => $id->value]);
        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /** @return array<string, int|string|null> */
    private function data(Race $race): array
    {
        return [
            'id' => $race->id->value, 'organization_id' => $race->organizationId?->value,
            'name' => $race->name, 'starts_at' => $race->startsAt->format('Y-m-d H:i:s.uP'),
            'timezone' => $race->timezone, 'sport_code' => $race->sportCode, 'distance_metres' => $race->distance->metres,
            'street' => $race->location->street, 'city' => $race->location->city, 'region' => $race->location->region,
            'postal_code' => $race->location->postalCode, 'country_code' => $race->location->countryCode,
            'latitude' => $race->location->latitude, 'longitude' => $race->location->longitude,
            'description' => $race->description, 'image_url' => $race->imageUrl, 'official_url' => $race->officialUrl,
            'publication_state' => $race->publicationState, 'participation' => $race->participation,
            'resale_deadline' => $race->resaleDeadline?->format('Y-m-d H:i:s.uP'),
            'min_price_cents' => $race->minPriceCents, 'max_price_cents' => $race->maxPriceCents,
            'registration_schema_version' => $race->registrationSchemaVersion,
            'registration_schema' => null === $race->registrationSchema ? null : json_encode($race->registrationSchema, JSON_THROW_ON_ERROR),
            'created_at' => $race->createdAt->format('Y-m-d H:i:s.uP'), 'updated_at' => $race->updatedAt->format('Y-m-d H:i:s.uP'),
            'version' => $race->version,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Race
    {
        $schema = null === $row['registration_schema'] ? null : json_decode(self::text($row['registration_schema']), false, 512, JSON_THROW_ON_ERROR);
        assert(null === $schema || $schema instanceof \stdClass);

        return new Race(
            new RaceId(self::text($row['id'])), self::text($row['name']), new \DateTimeImmutable(self::text($row['starts_at'])),
            self::text($row['timezone']), self::text($row['sport_code']), new Distance((int) self::text($row['distance_metres'])),
            new RaceLocation(self::text($row['city']), self::text($row['country_code']), self::nullableText($row['street']), self::nullableText($row['region']), self::nullableText($row['postal_code']), self::nullableText($row['latitude']), self::nullableText($row['longitude'])),
            new \DateTimeImmutable(self::text($row['created_at'])), new \DateTimeImmutable(self::text($row['updated_at'])), (int) self::text($row['version']),
            null === $row['organization_id'] ? null : new RaceId(self::text($row['organization_id'])),
            self::text($row['publication_state']), self::text($row['participation']), self::nullableText($row['description']), self::nullableText($row['image_url']), self::nullableText($row['official_url']),
            null === $row['resale_deadline'] ? null : new \DateTimeImmutable(self::text($row['resale_deadline'])),
            null === $row['min_price_cents'] ? null : (int) self::text($row['min_price_cents']), null === $row['max_price_cents'] ? null : (int) self::text($row['max_price_cents']),
            null === $row['registration_schema_version'] ? null : (int) self::text($row['registration_schema_version']), $schema,
        );
    }

    private static function text(mixed $value): string
    {
        assert(is_string($value) || is_int($value));

        return (string) $value;
    }

    private static function nullableText(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }
}
