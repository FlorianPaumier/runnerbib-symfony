<?php

declare(strict_types=1);

namespace App\Application\Checkout;

final readonly class CheckoutAuthorizationView
{
    public function __construct(public string $checkoutId, public \DateTimeImmutable $initialDeadline, public int $totalCents, public string $currency, public string $operationId)
    {
    }
}
