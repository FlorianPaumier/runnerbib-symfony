<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Identity\InvalidSignup;
use App\Application\Identity\RegisterAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class IdentityController extends AbstractController
{
    #[Route('/connexion', name: 'identity_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authentication): Response
    {
        return $this->render('identity/form.html.twig', ['kind' => 'login', 'heading' => 'Connexion', 'message' => null === $authentication->getLastAuthenticationError() ? null : 'Connexion impossible. Vérifiez vos informations.']);
    }

    #[Route('/inscription', name: 'identity_register', methods: ['GET', 'POST'])]
    public function register(Request $request, RegisterAccount $registration, #[Autowire(service: 'limiter.identity_request')] RateLimiterFactory $limiter): Response
    {
        if ($request->isMethod('GET')) {
            return $this->screen('signup', 'Créer un compte');
        }
        if (!$this->isCsrfTokenValid('identity', $request->request->getString('_csrf_token'))) {
            return $this->screen('signup', 'Créer un compte', 'Formulaire expiré.', 400);
        }
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->screen('signup', 'Créer un compte', 'Veuillez réessayer plus tard.', 429);
        }
        try {
            $registration->register($request->request->getString('email'), $request->request->getString('password'), $request->request->getBoolean('terms'));
        } catch (InvalidSignup $error) {
            return $this->screen('signup', 'Créer un compte', $error->getMessage(), 400);
        }

        return $this->screen('signup', 'Créer un compte', 'Si cette adresse peut être inscrite, un courriel de vérification sera envoyé.');
    }

    #[Route('/renvoyer-verification', name: 'identity_resend_verification', methods: ['GET', 'POST'])]
    public function resendVerification(Request $request, RegisterAccount $registration, #[Autowire(service: 'limiter.identity_request')] RateLimiterFactory $limiter): Response
    {
        if ($request->isMethod('GET')) {
            return $this->screen('verification', 'Renvoyer la vérification');
        }
        if (!$this->isCsrfTokenValid('identity', $request->request->getString('_csrf_token'))) {
            return $this->screen('verification', 'Renvoyer la vérification', 'Formulaire expiré.', 400);
        }
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->screen('verification', 'Renvoyer la vérification', 'Veuillez réessayer plus tard.', 429);
        }
        $registration->requestVerification($request->request->getString('email'));

        return $this->screen('verification', 'Renvoyer la vérification', 'Si une vérification est nécessaire, un nouveau courriel sera envoyé.');
    }

    #[Route('/verification/{token}', name: 'identity_verify', methods: ['GET'])]
    public function verify(string $token, RegisterAccount $registration): Response
    {
        $valid = $registration->verify($token);

        return $this->screen('message', 'Vérification du compte', $valid ? 'Votre adresse est vérifiée.' : 'Lien invalide ou expiré.', $valid ? 200 : 400);
    }

    #[Route('/mot-de-passe-oublie', name: 'identity_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request, RegisterAccount $registration, #[Autowire(service: 'limiter.identity_request')] RateLimiterFactory $limiter): Response
    {
        if ($request->isMethod('GET')) {
            return $this->screen('forgot', 'Réinitialiser le mot de passe');
        }
        if (!$this->isCsrfTokenValid('identity', $request->request->getString('_csrf_token'))) {
            return $this->screen('forgot', 'Réinitialiser le mot de passe', 'Formulaire expiré.', 400);
        }
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->screen('forgot', 'Réinitialiser le mot de passe', 'Veuillez réessayer plus tard.', 429);
        }
        $registration->requestReset($request->request->getString('email'));

        return $this->screen('forgot', 'Réinitialiser le mot de passe', 'Si un compte correspond, un courriel de réinitialisation sera envoyé.');
    }

    #[Route('/reinitialiser/{token}', name: 'identity_reset', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request, RegisterAccount $registration, #[Autowire(service: 'limiter.identity_request')] RateLimiterFactory $limiter): Response
    {
        if ($request->isMethod('GET')) {
            return $this->screen('reset', 'Choisir un nouveau mot de passe');
        }
        if (!$this->isCsrfTokenValid('identity', $request->request->getString('_csrf_token'))) {
            return $this->screen('reset', 'Choisir un nouveau mot de passe', 'Formulaire expiré.', 400);
        }
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->screen('reset', 'Choisir un nouveau mot de passe', 'Veuillez réessayer plus tard.', 429);
        }
        $valid = $registration->reset($token, $request->request->getString('password'));

        return $this->screen('reset', 'Choisir un nouveau mot de passe', $valid ? 'Mot de passe modifié. Reconnectez-vous.' : 'Lien ou mot de passe invalide.', $valid ? 200 : 400);
    }

    private function screen(string $kind, string $heading, ?string $message = null, int $status = 200): Response
    {
        return $this->render('identity/form.html.twig', ['kind' => $kind, 'heading' => $heading, 'message' => $message], new Response(status: $status, headers: ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store, private']));
    }
}
