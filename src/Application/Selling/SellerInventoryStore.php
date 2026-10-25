<?php

declare(strict_types=1);

namespace App\Application\Selling;

interface SellerInventoryStore
{
    /** @return list<SellerInventoryItem> */
    public function forSeller(string $sellerId): array;
}
