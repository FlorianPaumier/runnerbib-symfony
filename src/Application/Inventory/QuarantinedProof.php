<?php

declare(strict_types=1);

namespace App\Application\Inventory;

final readonly class QuarantinedProof
{
    public function __construct(public string $id, public string $objectKey, public string $mimeType, public int $sizeBytes, public string $checksum, public string $storageIdentity)
    {
    }
}
