<?php

declare(strict_types=1);

namespace App\Application\Inventory;

interface PrivateProofReader
{
    public function read(StoredProof $proof): string;
}
