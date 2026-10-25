<?php

declare(strict_types=1);

namespace App\Application\Races;

interface RaceDiscovery
{
    public function get(string $id): ?PublicRace;

    public function search(RaceSearch $search): RacePage;
}
