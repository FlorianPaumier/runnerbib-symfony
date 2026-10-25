<?php

declare(strict_types=1);

namespace App\Application\Inventory;

final readonly class StoredProof
{
    public function __construct(public string $id, public string $objectKey, public int $sizeBytes, public string $checksum)
    {
    }
}
