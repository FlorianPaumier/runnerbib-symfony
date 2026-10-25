<?php

declare(strict_types=1);

namespace App\Domain\Races;

final readonly class RaceId
{
    public function __construct(public string $value)
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value)) {
            throw new \InvalidArgumentException('Invalid UUID.');
        }
    }
}
