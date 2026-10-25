<?php

declare(strict_types=1);

namespace App\Application\Races;

use App\Domain\Races\Race;
use App\Domain\Races\RaceId;

interface RaceRepository
{
    public function save(Race $race, ?int $expectedVersion = null): Race;

    public function get(RaceId $id): ?Race;
}
