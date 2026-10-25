<?php

declare(strict_types=1);

namespace App\Application\Inventory;

interface PrivateProofStorage
{
    public function quarantine(string $objectId, string $temporaryPath, string $declaredMimeType): QuarantinedProof;

    public function discard(QuarantinedProof $proof): void;
}
