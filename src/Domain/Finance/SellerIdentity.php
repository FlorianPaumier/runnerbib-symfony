<?php

declare(strict_types=1);

namespace App\Domain\Finance;

final readonly class SellerIdentity
{
    public function __construct(public string $country, public string $email)
    {
        if (1 !== preg_match('/\A[A-Z]{2}\z/', $country) || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Explicit seller country and known email are required.');
        }
    }
}
