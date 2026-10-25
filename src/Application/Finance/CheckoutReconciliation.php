<?php

declare(strict_types=1);

namespace App\Application\Finance;

final readonly class CheckoutReconciliation
{
    public function __construct(public string $checkoutId, public string $operationId, public int $totalCents, public string $currency, public ?string $sessionReference, public int $generation, public \DateTimeImmutable $deadline, public string $apiVersion, public bool $attempted, public bool $cancellationRequested = false, public bool $closed = false)
    {
    }
}
