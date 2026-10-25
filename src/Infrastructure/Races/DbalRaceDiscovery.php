<?php

declare(strict_types=1);

namespace App\Infrastructure\Races;

use App\Application\Races\PublicRace;
use App\Application\Races\RaceCursor;
use App\Application\Races\RaceDiscovery;
use App\Application\Races\RacePage;
use App\Application\Races\RaceSearch;
use App\Domain\Races\RaceId;
use Doctrine\DBAL\Connection;

final readonly class DbalRaceDiscovery implements RaceDiscovery
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(string $id): ?PublicRace
    {
        $row = $this->connection->fetchAssociative("SELECT r.id,r.name,r.starts_at,r.timezone,r.sport_code,r.distance_metres,r.city,r.country_code,r.organization_id,o.name AS organization_name,r.description,r.image_url,r.official_url,r.participation FROM races r LEFT JOIN organizations o ON o.id=r.organization_id WHERE r.id=:id AND r.publication_state='published'", ['id' => $id]);

        return false === $row ? null : $this->map($row);
    }

    public function search(RaceSearch $search): RacePage
    {
        $conditions = ["r.publication_state='published'"];
        $parameters = [];
        foreach (['sport_code' => $search->sportCode, 'country_code' => $search->countryCode] as $column => $value) {
            if (null !== $value) {
                $conditions[] = 'r.'.$column.' = :'.$column;
                $parameters[$column] = $value;
            }
        }
        if (null !== $search->name) {
            $conditions[] = "r.name ILIKE :name ESCAPE '!'";
            $parameters['name'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search->name).'%';
        }
        if (null !== $search->city) {
            $conditions[] = 'lower(r.city) = lower(:city)';
            $parameters['city'] = $search->city;
        }
        foreach ([['starts_from', 'r.starts_at >=', $search->startsFrom?->format('Y-m-d H:i:s.uP')], ['starts_until', 'r.starts_at <=', $search->startsUntil?->format('Y-m-d H:i:s.uP')], ['min_distance', 'r.distance_metres >=', $search->minDistanceMetres], ['max_distance', 'r.distance_metres <=', $search->maxDistanceMetres]] as [$key, $operator, $value]) {
            if (null !== $value) {
                $conditions[] = $operator.' :'.$key;
                $parameters[$key] = $value;
            }
        }
        if (null !== $search->cursor) {
            $conditions[] = '(r.starts_at, r.id) > (:cursor_start, :cursor_id)';
            $parameters['cursor_start'] = $search->cursor->startsAt->format('Y-m-d H:i:s.uP');
            $parameters['cursor_id'] = $search->cursor->id->value;
        }
        $rows = $this->connection->fetchAllAssociative('SELECT r.id,r.name,r.starts_at,r.timezone,r.sport_code,r.distance_metres,r.city,r.country_code,r.organization_id,o.name AS organization_name,r.description,r.image_url,r.official_url,r.participation FROM races r LEFT JOIN organizations o ON o.id=r.organization_id WHERE '.implode(' AND ', $conditions).' ORDER BY r.starts_at,r.id LIMIT '.($search->limit + 1), $parameters);
        $races = array_map($this->map(...), $rows);

        $hasMore = count($races) > $search->limit;
        if ($hasMore) {
            array_pop($races);
        }
        $last = end($races);
        $cursor = $hasMore && false !== $last ? (new RaceCursor(new \DateTimeImmutable($last->startsAt), new RaceId($last->id)))->toToken() : null;

        return new RacePage($races, $cursor);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): PublicRace
    {
        return new PublicRace(self::text($row['id']), self::text($row['name']), (new \DateTimeImmutable(self::text($row['starts_at'])))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'), self::text($row['timezone']), self::text($row['sport_code']), (int) self::text($row['distance_metres']), self::text($row['city']), self::text($row['country_code']), self::nullableText($row['organization_id']), self::nullableText($row['organization_name']), self::nullableText($row['description']), self::nullableText($row['image_url']), self::nullableText($row['official_url']), self::text($row['participation']));
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
