<?php

declare(strict_types=1);

namespace App\Infrastructure\Selling;

use App\Application\Selling\PublicListingCatalog;
use Doctrine\DBAL\Connection;

final readonly class DbalPublicListingCatalog implements PublicListingCatalog
{
    public function __construct(private Connection $database)
    {
    }

    public function recentListingIds(): array
    {
        $ids = [];
        foreach ($this->database->fetchFirstColumn("SELECT l.id::text FROM resale_listings l JOIN races r ON r.id=l.race_id WHERE l.state='published' AND r.publication_state='published' ORDER BY l.published_at DESC,l.id LIMIT 6") as $id) {
            assert(is_string($id));
            $ids[] = $id;
        }

        return $ids;
    }
}
