<?php

declare(strict_types=1);

namespace App\Application\Races;

final readonly class PublicRace
{
    public function __construct(
        public string $id,
        public string $name,
        public string $startsAt,
        public string $timezone,
        public string $sportCode,
        public int $distanceMetres,
        public string $city,
        public string $countryCode,
        public ?string $organizationId,
        public ?string $organizationName,
        public ?string $description,
        public ?string $imageUrl,
        public ?string $officialUrl,
        public string $participation,
    ) {
    }
}
