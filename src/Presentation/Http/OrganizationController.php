<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Identity\CurrentIdentity;
use App\Application\Organizations\ManageOrganizations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class OrganizationController extends AbstractController
{
    #[Route('/organisations', name: 'organizations', methods: ['GET'])]
    public function index(CurrentIdentity $identity, ManageOrganizations $organizations): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }
        try {
            $organization = $organizations->mine($accountId);
        } catch (\DomainException $exception) {
            return $this->render('organizations/forbidden.html.twig', [], new Response(status: 403));
        }

        return $this->render('organizations/index.html.twig', ['organization' => $organization]);
    }

    #[Route('/organisations/{id}', name: 'organization_dashboard', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function dashboard(string $id, CurrentIdentity $identity, ManageOrganizations $organizations): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }
        try {
            $organization = $organizations->get($accountId, $id);
        } catch (\DomainException $exception) {
            return $this->render('organizations/forbidden.html.twig', [], new Response(status: 403));
        }

        return $this->render('organizations/dashboard.html.twig', ['organization' => $organization]);
    }

    #[Route('/organisations/demande', name: 'organization_request', methods: ['GET', 'POST'])]
    public function requestAccess(Request $request, CurrentIdentity $identity, ManageOrganizations $organizations): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }
        try {
            $organizations->mine($accountId);
        } catch (\DomainException) {
            return $this->render('organizations/forbidden.html.twig', [], new Response(status: 403));
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('organization_request', $request->request->getString('_token'))) {
                return new Response('Action non autorisée.', 403);
            }
            try {
                $organizations->request(Uuid::v7()->toRfc4122(), $request->request->getString('nom'), $accountId, Uuid::v7()->toRfc4122(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            } catch (\InvalidArgumentException) {
                return $this->render('organizations/request.html.twig', ['error' => 'Indiquez le nom de votre organisation.'], new Response(status: 400, headers: ['Cache-Control' => 'private, no-store']));
            } catch (\DomainException) {
                return $this->render('organizations/request.html.twig', ['error' => 'Votre demande entre en conflit avec votre accès actuel. Consultez votre organisation.'], new Response(status: 409, headers: ['Cache-Control' => 'private, no-store']));
            }

            return $this->redirectToRoute('organizations');
        }

        return $this->render('organizations/request.html.twig', [], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }
}
