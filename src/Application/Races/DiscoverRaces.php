<?php

declare(strict_types=1);

namespace App\Application\Races;

final readonly class DiscoverRaces
{
    public function __construct(private RaceDiscovery $discovery)
    {
    }

    public function search(RaceSearch $search): RacePage
    {
        return $this->discovery->search($search);
    }
}
