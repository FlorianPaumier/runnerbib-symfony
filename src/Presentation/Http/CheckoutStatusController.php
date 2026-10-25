<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Checkout\FindCheckoutStatus;
use App\Application\Identity\CurrentIdentity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CheckoutStatusController extends AbstractController
{
    #[Route('/achat/{id}/retour', name: 'checkout_status', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function status(string $id, CurrentIdentity $identity, FindCheckoutStatus $checkouts): Response
    {
        if (null === $identity->accountId()) {
            return $this->redirectToRoute('identity_login');
        }
        $checkout = $checkouts->find($id);

        return $this->render('checkout/status.html.twig', ['checkout' => $checkout], new Response(status: null === $checkout ? 404 : 200, headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/achat/retour', name: 'checkout_return', methods: ['GET'])]
    public function returned(Request $request, CurrentIdentity $identity, FindCheckoutStatus $checkouts): Response
    {
        if (null === $identity->accountId()) {
            return $this->redirectToRoute('identity_login');
        }
        $checkout = $checkouts->findBySession($request->query->getString('session'));

        return $this->render('checkout/status.html.twig', ['checkout' => $checkout], new Response(status: null === $checkout ? 404 : 200, headers: ['Cache-Control' => 'private, no-store']));
    }
}
