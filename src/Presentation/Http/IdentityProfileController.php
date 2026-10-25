<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Identity\ManageProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class IdentityProfileController extends AbstractController
{
    #[Route('/compte', name: 'identity_account', methods: ['GET', 'POST'])]
    public function account(Request $request, ManageProfile $profiles): Response
    {
        $message = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('identity_profile', $request->request->getString('_csrf_token'))) {
                $message = 'Formulaire expiré.';
                $status = 400;
            } else {
                try {
                    $updated = $profiles->update($request->request->getString('display_name'), $request->request->getBoolean('optional_email_enabled'), $request->request->getBoolean('optional_in_app_enabled'), $request->request->getInt('version'));
                    $message = $updated ? 'Profil enregistré. Reconnectez-vous pour continuer.' : 'Le profil a changé. Rechargez la page et réessayez.';
                    $status = $updated ? 200 : 409;
                } catch (\DomainException) {
                    $message = 'Ces préférences ne peuvent pas être activées pour le moment. Vos modifications n’ont pas été enregistrées.';
                    $status = 400;
                }
            }
        }
        $profile = $profiles->current();
        if (null === $profile) {
            throw $this->createNotFoundException('Compte indisponible.');
        }

        return $this->render('identity/profile.html.twig', ['profile' => $profile, 'message' => $message], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/coureurs/{accountId}', name: 'identity_public_profile', requirements: ['accountId' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function publicProfile(string $accountId, ManageProfile $profiles): Response
    {
        $profile = $profiles->publicProfile($accountId);
        if (null === $profile) {
            throw $this->createNotFoundException('Profil indisponible.');
        }

        return $this->render('identity/public_profile.html.twig', ['profile' => $profile]);
    }
}
