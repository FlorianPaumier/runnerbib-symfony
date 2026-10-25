<?php

declare(strict_types=1);

namespace App\Application\Finance;

final readonly class HostedCheckoutIntent
{
    public function __construct(public string $operationId, public string $checkoutId, public int $totalCents, public string $currency, public \stdClass $parameters, public int $generation, public ?string $sessionReference = null)
    {
    }
}
