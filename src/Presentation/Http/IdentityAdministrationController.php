<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Identity\AdministerAccounts;
use App\Application\Identity\InvalidLifecycleChange;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class IdentityAdministrationController extends AbstractController
{
    #[Route('/admin/comptes/{accountId}/statut', name: 'identity_admin_state', requirements: ['accountId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET', 'POST'])]
    public function change(string $accountId, Request $request, AdministerAccounts $accounts): Response
    {
        $message = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('account_lifecycle:'.$accountId, $request->request->getString('_csrf_token'))) {
                $message = 'Formulaire expiré.';
                $status = 400;
            } else {
                try {
                    $changed = $accounts->change($accountId, $request->request->getString('state'), $request->request->getString('reason'));
                    $message = $changed ? 'État du compte modifié.' : 'Action refusée ou compte indisponible.';
                    $status = $changed ? 200 : 403;
                } catch (InvalidLifecycleChange $error) {
                    $message = $error->getMessage();
                    $status = 400;
                }
            }
        }

        return $this->render('identity/administration.html.twig', ['accountId' => $accountId, 'message' => $message], new Response(status: $status));
    }
}
