<?php

declare(strict_types=1);

namespace App\Application\Selling;

use App\Application\Identity\CurrentIdentity;

final readonly class SellerInventory
{
    public function __construct(private CurrentIdentity $identity, private SellerInventoryStore $store)
    {
    }

    /**
     * @return list<SellerInventoryItem>
     *
     * @phpstan-impure
     */
    public function list(): array
    {
        $sellerId = $this->identity->accountId();

        return null === $sellerId ? [] : $this->store->forSeller($sellerId);
    }
}
