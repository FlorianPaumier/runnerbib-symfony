<?php

declare(strict_types=1);

namespace App\Application\Health;

final readonly class CheckReadiness
{
    public function __construct(private ReadinessProbe $probe)
    {
    }

    public function __invoke(): bool
    {
        return $this->probe->isReady();
    }
}
