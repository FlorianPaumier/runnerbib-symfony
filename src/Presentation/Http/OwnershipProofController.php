<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Identity\CurrentIdentity;
use App\Application\Inventory\DownloadOwnershipProof;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class OwnershipProofController
{
    #[Route('/admin/preuves/{proofId}/document', name: 'ownership_proof_document', requirements: ['proofId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function document(string $proofId, CurrentIdentity $identity, DownloadOwnershipProof $proofs): Response
    {
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return new Response('Document indisponible.', 404, $headers);
        }
        try {
            $proof = $proofs->execute($accountId, $proofId);
        } catch (\RuntimeException|\LogicException) {
            return new Response('Document temporairement indisponible.', 503, $headers);
        }
        if (null === $proof) {
            return new Response('Document indisponible.', 404, $headers);
        }
        $response = new Response($proof->contents, 200, $headers + ['Content-Type' => 'application/octet-stream']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $proof->filename));

        return $response;
    }
}
