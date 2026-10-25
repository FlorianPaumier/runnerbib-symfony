<?php

declare(strict_types=1);

namespace App\Domain\Races;

final readonly class RaceLocation
{
    public string $city;
    public ?string $street;
    public ?string $region;
    public ?string $postalCode;

    public function __construct(
        string $city,
        public string $countryCode = 'FR',
        ?string $street = null,
        ?string $region = null,
        ?string $postalCode = null,
        public ?string $latitude = null,
        public ?string $longitude = null,
    ) {
        if ('FR' !== $countryCode) {
            throw new \InvalidArgumentException('Unsupported race market.');
        }
        if ((null === $latitude) !== (null === $longitude)) {
            throw new \InvalidArgumentException('Coordinates must be paired.');
        }
        foreach ([[$latitude, 90], [$longitude, 180]] as [$coordinate, $limit]) {
            if (null !== $coordinate && (1 !== preg_match('/^-?\d{1,3}(?:\.\d{1,6})?$/D', $coordinate) || abs((float) $coordinate) > $limit)) {
                throw new \InvalidArgumentException('Invalid coordinate.');
            }
        }
        $this->city = RaceText::required($city, 255);
        $this->street = RaceText::optional($street, 255);
        $this->region = RaceText::optional($region, 255);
        $this->postalCode = RaceText::optional($postalCode, 16);
    }
}
