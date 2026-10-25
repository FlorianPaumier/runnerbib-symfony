<?php

declare(strict_types=1);

namespace App\Application\Selling;

interface PublicListingCatalog
{
    /** @return list<string> */
    public function recentListingIds(): array;
}
