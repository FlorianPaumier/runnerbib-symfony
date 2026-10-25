<?php

declare(strict_types=1);

namespace App\Application\Races;

final readonly class RaceSearch
{
    public function __construct(
        public ?string $name = null,
        public ?\DateTimeImmutable $startsFrom = null,
        public ?\DateTimeImmutable $startsUntil = null,
        public ?string $sportCode = null,
        public ?string $city = null,
        public ?string $countryCode = null,
        public ?int $minDistanceMetres = null,
        public ?int $maxDistanceMetres = null,
        public int $limit = 20,
        public ?RaceCursor $cursor = null,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Page size must be between 1 and 100.');
        }
        if (null !== $startsFrom && null !== $startsUntil && $startsFrom > $startsUntil) {
            throw new \InvalidArgumentException('Start-date range is inverted.');
        }
        foreach ([$minDistanceMetres, $maxDistanceMetres] as $distance) {
            if (null !== $distance && $distance < 1) {
                throw new \InvalidArgumentException('Distance filter must be positive.');
            }
        }
        if (null !== $minDistanceMetres && null !== $maxDistanceMetres && $minDistanceMetres > $maxDistanceMetres) {
            throw new \InvalidArgumentException('Distance range is inverted.');
        }
    }
}
