<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Domain\Finance\SellerIdentity;

final readonly class SellerCreationOperation
{
    public function __construct(public string $id, public SellerIdentity $identity, public \DateTimeImmutable $createdAt)
    {
    }
}
