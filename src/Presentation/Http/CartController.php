<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Checkout\CartItemView;
use App\Application\Checkout\ManageCart;
use App\Application\Checkout\StartCheckout;
use App\Application\Identity\CurrentIdentity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CartController extends AbstractController
{
    #[Route('/panier', name: 'cart', methods: ['GET'])]
    public function index(CurrentIdentity $identity, ManageCart $cart): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }

        return $this->render('cart/index.html.twig', ['cart' => $cart->view($accountId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/panier/{id}/ajouter', name: 'cart_add', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function add(string $id, Request $request, CurrentIdentity $identity, ManageCart $cart): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }
        if (!$this->isCsrfTokenValid('cart_add_'.$id, $request->request->getString('_token'))) {
            return new Response('Action non autorisée.', 403);
        }
        try {
            $cart->add($accountId, $id, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\DomainException) {
            return new Response('Cette offre est indisponible ou une course figure déjà dans votre panier.', 409);
        }

        return $this->redirectToRoute('cart');
    }

    #[Route('/panier/{id}/retirer', name: 'cart_remove', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function remove(string $id, Request $request, CurrentIdentity $identity, ManageCart $cart): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }
        if (!$this->isCsrfTokenValid('cart_remove_'.$id, $request->request->getString('_token'))) {
            return new Response('Action non autorisée.', 403);
        }
        $cart->remove($accountId, $id);

        return $this->redirectToRoute('cart');
    }

    #[Route('/panier/payer', name: 'checkout_start', methods: ['POST'])]
    public function pay(Request $request, CurrentIdentity $identity, ManageCart $cart, StartCheckout $checkout): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }
        if (!$this->isCsrfTokenValid('checkout_start', $request->request->getString('_token'))) {
            return new Response('Action non autorisée.', 403);
        }
        $view = $cart->view($accountId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        if (!$view->payable) {
            return new Response('Ce panier ne peut pas être payé en l’état. Vérifiez les informations demandées et la disponibilité des offres.', 409);
        }
        $registration = [];
        foreach ($view->items as $item) {
            $registration[$item->listingId] = $this->registration($request, $item);
        }
        try {
            $start = $checkout->start($accountId, $registration, array_keys(array_filter($request->request->all('acknowledgment'), static fn (mixed $value): bool => '1' === $value)), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\DomainException) {
            return new Response('Ce panier ne peut pas être payé en l’état. Vérifiez les informations demandées et la disponibilité des offres.', 409);
        } catch (\RuntimeException) {
            return new Response('Le paiement est momentanément indisponible. Votre panier reste intact ; réessayez plus tard.', 503);
        }

        return $this->redirect($start->paymentUrl ?? $this->generateUrl('checkout_status', ['id' => $start->checkoutId]));
    }

    private function registration(Request $request, CartItemView $item): \stdClass
    {
        $posted = $request->request->all('registration');
        $answers = is_array($posted[$item->listingId] ?? null) ? $posted[$item->listingId] : [];
        $values = new \stdClass();
        foreach ($item->registrationFields as $field) {
            if (!array_key_exists($field['name'], $answers)) {
                continue;
            }
            $answer = $answers[$field['name']];
            if (!is_scalar($answer)) {
                $values->{$field['name']} = $answer;
                continue;
            }
            $values->{$field['name']} = match ($field['type']) {
                'integer' => false !== filter_var($answer, FILTER_VALIDATE_INT) ? (int) $answer : $answer,
                'boolean' => filter_var($answer, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
                default => (string) $answer,
            };
        }

        return $values;
    }
}
