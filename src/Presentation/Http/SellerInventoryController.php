<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Finance\SellerProviderUnavailable;
use App\Application\Selling\ManageListings;
use App\Application\Selling\SellerInventory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class SellerInventoryController extends AbstractController
{
    #[Route('/compte/ventes', name: 'seller_inventory', methods: ['GET'])]
    public function index(SellerInventory $inventory): Response
    {
        return $this->page($inventory);
    }

    #[Route('/compte/ventes/dossards/{entitlementId}/brouillon', name: 'seller_draft', requirements: ['entitlementId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function draft(string $entitlementId, Request $request, ManageListings $listings, SellerInventory $inventory): Response
    {
        $submitted = ['entitlementId' => $entitlementId, 'listingId' => $request->request->getString('listing_id'), 'price' => $request->request->getString('price')];
        if (!$this->isCsrfTokenValid('seller_draft_'.$entitlementId, $request->request->getString('_token'))) {
            return $this->page($inventory, 'Formulaire expiré. Réessayez depuis cette page.', 403, $submitted);
        }
        try {
            if (!Uuid::isValid($submitted['listingId'])) {
                throw new \InvalidArgumentException('Invalid draft identity.');
            }
            $created = $listings->draft($submitted['listingId'], $entitlementId, $this->priceCents($submitted['price']));
        } catch (\InvalidArgumentException|\OverflowException) {
            return $this->page($inventory, 'Indiquez un prix en euros avec au plus deux décimales et un net vendeur positif.', 400, $submitted);
        }
        if (!$created) {
            return $this->page($inventory, 'Ce dossard ou ce brouillon n’est plus disponible. Rechargez la page.', 409, $submitted);
        }

        return $this->redirectToRoute('seller_inventory', status: 303);
    }

    #[Route('/compte/ventes/annonces/{listingId}', name: 'seller_listing', requirements: ['listingId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function update(string $listingId, Request $request, ManageListings $listings, SellerInventory $inventory): Response
    {
        $action = $request->request->getString('action');
        $submitted = ['listingId' => $listingId, 'price' => $request->request->getString('price')];
        if (!in_array($action, ['justificatif', 'publier', 'prix', 'retirer'], true)) {
            return $this->page($inventory, 'Action inconnue. Réessayez depuis cette page.', 400, $submitted);
        }
        if (!$this->isCsrfTokenValid('seller_listing_'.$action.'_'.$listingId, $request->request->getString('_token'))) {
            return $this->page($inventory, 'Formulaire expiré. Réessayez depuis cette page.', 403, $submitted);
        }
        try {
            $version = filter_var($request->request->getString('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (false === $version) {
                throw new \InvalidArgumentException('Invalid listing version.');
            }
            $proofId = $request->request->getString('proof_id');
            if ('justificatif' === $action && 1 !== preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $proofId)) {
                throw new \InvalidArgumentException('Invalid proof identity.');
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $updated = match ($action) {
                'justificatif' => $listings->attachProof($listingId, $proofId, $version),
                'publier' => $listings->publish($listingId, $version, $now),
                'prix' => $listings->reprice($listingId, $this->priceCents($submitted['price']), $version, $now),
                'retirer' => $listings->withdraw($listingId, $version, $now),
            };
        } catch (SellerProviderUnavailable) {
            return $this->page($inventory, 'Cette action est temporairement indisponible. Votre annonce est conservée.', 503, $submitted);
        } catch (\InvalidArgumentException|\OverflowException) {
            return $this->page($inventory, 'Vérifiez le formulaire : prix en euros avec au plus deux décimales, net vendeur positif et justificatif valide.', 400, $submitted);
        } catch (\DomainException) {
            $updated = false;
        }
        if (!$updated) {
            return $this->page($inventory, 'L’annonce est indisponible ou a changé. Vérifiez le justificatif, le compte vendeur et la date limite de la course, puis rechargez la page.', 409, $submitted);
        }

        return $this->redirectToRoute('seller_inventory', status: 303);
    }

    private function priceCents(string $price): int
    {
        if (1 !== preg_match('/^([0-9]+)(?:[,.]([0-9]{1,2}))?$/D', trim($price), $parts)) {
            throw new \InvalidArgumentException('Invalid decimal price.');
        }
        $cents = filter_var(ltrim($parts[1].str_pad($parts[2] ?? '', 2, '0'), '0') ?: '0', FILTER_VALIDATE_INT);
        if (false === $cents) {
            throw new \InvalidArgumentException('Price exceeds supported integer cents.');
        }

        return $cents;
    }

    /** @param array{entitlementId?: string, listingId?: string, price?: string} $submitted */
    private function page(SellerInventory $inventory, ?string $error = null, int $status = 200, array $submitted = []): Response
    {
        $items = $inventory->list();
        $draftIds = [];
        $submittedId = $submitted['listingId'] ?? '';
        foreach ($items as $item) {
            $draftIds[$item->entitlementId] = ($submitted['entitlementId'] ?? null) === $item->entitlementId && Uuid::isValid($submittedId) ? $submittedId : Uuid::v7()->toRfc4122();
        }

        return $this->render('selling/inventory.html.twig', ['items' => $items, 'draftIds' => $draftIds, 'error' => $error, 'submitted' => $submitted], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }
}
