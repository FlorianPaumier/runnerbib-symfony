<?php

declare(strict_types=1);

namespace App\Application\Races;

use App\Domain\Races\Distance;
use App\Domain\Races\Race;
use App\Domain\Races\RaceId;
use App\Domain\Races\RaceLocation;

final readonly class RaceDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public \DateTimeImmutable $startsAt,
        public string $timezone,
        public string $sportCode,
        public int $distanceMetres,
        public string $city,
        public string $countryCode = 'FR',
        public ?string $organizationId = null,
        public string $publicationState = 'draft',
        public string $participation = 'nonparticipating',
        public ?string $street = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $latitude = null,
        public ?string $longitude = null,
        public ?string $description = null,
        public ?string $imageUrl = null,
        public ?string $officialUrl = null,
        public ?\DateTimeImmutable $resaleDeadline = null,
        public ?int $minPriceCents = null,
        public ?int $maxPriceCents = null,
        public ?int $registrationSchemaVersion = null,
        public ?\stdClass $registrationSchema = null,
    ) {
    }

    public function toRace(\DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt, int $version = 1): Race
    {
        return new Race(new RaceId($this->id), $this->name, $this->startsAt, $this->timezone, $this->sportCode, new Distance($this->distanceMetres), new RaceLocation($this->city, $this->countryCode, $this->street, $this->region, $this->postalCode, $this->latitude, $this->longitude), $createdAt, $updatedAt, $version, null === $this->organizationId ? null : new RaceId($this->organizationId), $this->publicationState, $this->participation, $this->description, $this->imageUrl, $this->officialUrl, $this->resaleDeadline, $this->minPriceCents, $this->maxPriceCents, $this->registrationSchemaVersion, $this->registrationSchema);
    }
}
