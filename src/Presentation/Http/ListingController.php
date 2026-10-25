<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Identity\CurrentIdentity;
use App\Application\Selling\FindListingOffer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListingController extends AbstractController
{
    #[Route('/dossards/{id}', name: 'listing_offer', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function offer(string $id, CurrentIdentity $identity, FindListingOffer $offers): Response
    {
        $offer = $offers->get($id, $identity->accountId(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        if (null === $offer) {
            return new Response('Offre introuvable.', 404);
        }

        return $this->render('selling/offer.html.twig', ['offer' => $offer], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }
}
