<?php

declare(strict_types=1);

namespace App\Application\Inventory;

final readonly class DownloadOwnershipProof
{
    public function __construct(private OwnershipProofStore $proofs, private PrivateProofReader $storage)
    {
    }

    public function execute(string $reviewerId, string $proofId): ?ProofDownload
    {
        $proof = $this->proofs->documentForReview($reviewerId, $proofId);
        if (null === $proof) {
            return null;
        }
        $contents = $this->storage->read($proof);
        // The reviewer authority is re-checked after the read: access revoked meanwhile cancels the download.
        if ($proof != $this->proofs->documentForReview($reviewerId, $proofId)) {
            return null;
        }

        return new ProofDownload($contents, $proofId.'.blob');
    }
}
