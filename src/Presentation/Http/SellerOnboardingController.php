<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Finance\SellerOnboarding;
use App\Application\Finance\SellerProviderUnavailable;
use App\Application\Identity\CurrentIdentity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SellerOnboardingController extends AbstractController
{
    #[Route('/compte/vendeur', name: 'seller_onboarding', methods: ['GET', 'POST'])]
    public function onboarding(Request $request, CurrentIdentity $identity, SellerOnboarding $onboarding): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }
        $state = null;
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('seller_onboarding', $request->request->getString('_token'))) {
                return new Response('Action non autorisée.', 403);
            }
            try {
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $state = 'reconcile' === $request->request->getString('action')
                    ? $onboarding->reconcile($accountId, $now)
                    : $onboarding->start($accountId, strtoupper(trim($request->request->getString('country'))), $now);
                if (null !== $state->onboardingUrl) {
                    return $this->redirect($state->onboardingUrl, 303);
                }
            } catch (SellerProviderUnavailable) {
                $error = 'La configuration du compte vendeur est temporairement indisponible. Réessayez plus tard.';
                $status = 503;
            } catch (\InvalidArgumentException) {
                $error = 'Indiquez le code de votre pays de résidence, sur deux lettres.';
                $status = 400;
            } catch (\DomainException) {
                $error = 'Cette action nécessite un compte actif et vérifié ainsi qu’une configuration vendeur valide.';
                $status = 403;
            }
        }

        return $this->render('seller/onboarding.html.twig', ['state' => $state, 'error' => $error], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }
}
