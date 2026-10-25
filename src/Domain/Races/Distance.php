<?php

declare(strict_types=1);

namespace App\Domain\Races;

final readonly class Distance
{
    public function __construct(public int $metres)
    {
        if ($metres <= 0 || $metres > 2147483647) {
            throw new \InvalidArgumentException('Distance must fit positive integer metres.');
        }
    }
}
