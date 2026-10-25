<?php

declare(strict_types=1);

namespace App\Application\Races;

use App\Domain\Races\RaceId;

final readonly class FindPublicRace
{
    public function __construct(private RaceDiscovery $discovery)
    {
    }

    public function get(string $id): ?PublicRace
    {
        return $this->discovery->get((new RaceId($id))->value);
    }
}
