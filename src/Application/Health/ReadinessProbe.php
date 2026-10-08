<?php

declare(strict_types=1);

namespace App\Application\Health;

interface ReadinessProbe
{
    public function isReady(): bool;
}
