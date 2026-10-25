<?php

declare(strict_types=1);

namespace App\Application\Races;

final readonly class RacePage
{
    /** @param list<PublicRace> $races */
    public function __construct(public array $races, public ?string $nextCursor)
    {
    }
}
