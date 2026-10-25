<?php

declare(strict_types=1);

namespace App\Application\Inventory;

final readonly class ProofDownload
{
    public function __construct(public string $contents, public string $filename)
    {
    }
}
