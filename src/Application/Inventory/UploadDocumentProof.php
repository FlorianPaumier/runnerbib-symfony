<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Inventory\BibNumber;

final readonly class UploadDocumentProof
{
    public function __construct(private OwnershipProofStore $proofs, private PrivateProofStorage $storage)
    {
    }

    public function execute(string $accountId, string $raceId, string $number, string $temporaryPath, string $declaredMimeType, string $proofId, string $objectId, string $auditId, \DateTimeImmutable $now): bool
    {
        $bib = new BibNumber($number);
        if (!$this->proofs->canSubmitDocument($accountId, $raceId, $bib)) {
            return false;
        }
        $object = $this->storage->quarantine($objectId, $temporaryPath, $declaredMimeType);
        $saved = false;
        try {
            $saved = $this->proofs->submitDocument($accountId, $raceId, $bib, $proofId, $object, $auditId, $now);

            return $saved;
        } finally {
            if (!$saved) {
                $this->storage->discard($object);
            }
        }
    }
}
