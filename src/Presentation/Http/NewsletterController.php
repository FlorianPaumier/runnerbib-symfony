<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Newsletter\ManageNewsletter;
use App\Application\Newsletter\UnconfiguredNewsletterPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class NewsletterController extends AbstractController
{
    #[Route('/newsletter', name: 'newsletter', methods: ['GET', 'POST'])]
    public function signup(Request $request, ManageNewsletter $newsletter, #[Autowire(service: 'limiter.newsletter_request')] RateLimiterFactory $limiter): Response
    {
        if ($request->isMethod('GET')) {
            return $this->screen();
        }
        if (!$this->isCsrfTokenValid('newsletter', $request->request->getString('_token'))) {
            return $this->screen('Formulaire expiré.', 403);
        }
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->screen('Veuillez réessayer plus tard.', 429);
        }
        try {
            $newsletter->subscribe(Uuid::v7()->toRfc4122(), $request->request->getString('email'), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (UnconfiguredNewsletterPolicy) {
            return $this->screen('L’inscription à la newsletter est temporairement indisponible. Vous pouvez toujours vous désinscrire avec le lien de votre courriel.', 503);
        } catch (\InvalidArgumentException) {
            return $this->screen('Indiquez une adresse courriel valide.', 400);
        }

        return $this->screen('Votre demande d’inscription est prise en compte.');
    }

    #[Route('/newsletter/desinscription/{token}', name: 'newsletter_unsubscribe', methods: ['GET', 'POST'])]
    public function unsubscribe(string $token, Request $request, ManageNewsletter $newsletter): Response
    {
        try {
            $newsletter->unsubscribe($token, $request->isMethod('POST') ? $request->request->getString('raison') : null, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\InvalidArgumentException) {
            return $this->screen('Lien de désinscription invalide.', 400);
        }

        return $this->screen('Votre demande de désinscription est prise en compte.');
    }

    private function screen(?string $message = null, int $status = 200): Response
    {
        return $this->render('newsletter/index.html.twig', ['message' => $message], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']));
    }
}
