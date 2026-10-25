<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Finance\ReceiveProviderEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProviderWebhookController
{
    #[Route('/webhooks/stripe', name: 'provider_stripe_webhook', methods: ['POST'])]
    public function receive(Request $request, ReceiveProviderEvent $events): Response
    {
        try {
            $events->receive($request->getContent(), $request->headers->get('Stripe-Signature', ''), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\InvalidArgumentException) {
            return new Response('Requête invalide.', 400, ['Cache-Control' => 'no-store']);
        } catch (\RuntimeException) {
            return new Response('Service temporairement indisponible.', 503, ['Cache-Control' => 'no-store']);
        }

        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }
}
