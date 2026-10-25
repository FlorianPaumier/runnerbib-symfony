<?php

declare(strict_types=1);

namespace App\Domain\Races;

final readonly class Race
{
    public \DateTimeImmutable $startsAt;
    public string $name;
    public ?string $description;
    public ?string $imageUrl;
    public ?string $officialUrl;

    public function __construct(
        public RaceId $id,
        string $name,
        \DateTimeImmutable $startsAt,
        public string $timezone,
        public string $sportCode,
        public Distance $distance,
        public RaceLocation $location,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public int $version = 1,
        public ?RaceId $organizationId = null,
        public string $publicationState = 'draft',
        public string $participation = 'nonparticipating',
        ?string $description = null,
        ?string $imageUrl = null,
        ?string $officialUrl = null,
        public ?\DateTimeImmutable $resaleDeadline = null,
        public ?int $minPriceCents = null,
        public ?int $maxPriceCents = null,
        public ?int $registrationSchemaVersion = null,
        public ?\stdClass $registrationSchema = null,
    ) {
        $this->name = RaceText::required($name, 255);
        $this->description = RaceText::optional($description);
        $this->imageUrl = RaceText::optional($imageUrl);
        $this->officialUrl = RaceText::optional($officialUrl);
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Invalid IANA timezone.');
        }
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $sportCode)) {
            throw new \InvalidArgumentException('Invalid sport code.');
        }
        if ($version < 1 || $updatedAt < $createdAt) {
            throw new \InvalidArgumentException('Invalid version or timestamp ordering.');
        }
        if (!in_array($publicationState, ['draft', 'published', 'archived'], true)) {
            throw new \InvalidArgumentException('Invalid publication state.');
        }
        if (!in_array($participation, ['participating', 'nonparticipating'], true)) {
            throw new \InvalidArgumentException('Invalid participation.');
        }
        if ('participating' === $participation && null === $organizationId) {
            throw new \InvalidArgumentException('Participating race requires organization.');
        }
        if ('nonparticipating' === $participation && (null !== $minPriceCents || null !== $maxPriceCents)) {
            throw new \InvalidArgumentException('Nonparticipating race cannot set price bounds.');
        }
        foreach ([$minPriceCents, $maxPriceCents, $registrationSchemaVersion] as $positiveValue) {
            if (null !== $positiveValue && $positiveValue < 1) {
                throw new \InvalidArgumentException('Bounds and schema version must be positive.');
            }
        }
        if (null !== $minPriceCents && null !== $maxPriceCents && $minPriceCents > $maxPriceCents) {
            throw new \InvalidArgumentException('Minimum price exceeds maximum.');
        }
        foreach ([$this->imageUrl, $this->officialUrl] as $url) {
            if (null !== $url && (false === filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
                throw new \InvalidArgumentException('Invalid public URL.');
            }
        }
        if ((null === $registrationSchemaVersion) !== (null === $registrationSchema)) {
            throw new \InvalidArgumentException('Registration definition and version must be paired.');
        }
        if (null !== $registrationSchema) {
            new \App\Domain\Registration\RegistrationSchema($registrationSchema);
        }
        $this->startsAt = $startsAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public function assertSameOrganization(self $replacement): void
    {
        if ($this->organizationId?->value !== $replacement->organizationId?->value) {
            throw new \DomainException('Organization changes require a separate authorized workflow.');
        }
    }

    public function assertSameParticipation(self $replacement): void
    {
        if ($this->participation !== $replacement->participation) {
            throw new \DomainException('Participation changes require a separate authorized workflow.');
        }
    }

    public function archive(\DateTimeImmutable $now): self
    {
        return new self($this->id, $this->name, $this->startsAt, $this->timezone, $this->sportCode, $this->distance, $this->location, $this->createdAt, $now, $this->version, $this->organizationId, 'archived', $this->participation, $this->description, $this->imageUrl, $this->officialUrl, $this->resaleDeadline, $this->minPriceCents, $this->maxPriceCents, $this->registrationSchemaVersion, $this->registrationSchema);
    }
}
