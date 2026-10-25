<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Privacy\ManagePrivacyRequests;
use App\Application\Privacy\PrivacyRequestRecord;
use App\Application\Privacy\UnconfiguredPrivacyProgressPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PrivacyController extends AbstractController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];

    #[Route('/compte/confidentialite', name: 'privacy_requests', methods: ['GET', 'POST'])]
    public function requests(Request $request, ManagePrivacyRequests $privacy): Response
    {
        if ($request->isMethod('POST')) {
            try {
                if (!$this->isCsrfTokenValid('privacy_request', $request->request->getString('_token'))) {
                    return $this->requestPage('Formulaire expiré. Réessayez depuis cette page.', 403);
                }
                $receipt = $privacy->request($request->request->getString('request_id'), $request->request->getString('kind'), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            } catch (UnconfiguredPrivacyProgressPolicy) {
                return $this->requestPage('Les demandes sont temporairement indisponibles. Votre compte et vos annonces sont conservés.', 503);
            } catch (\InvalidArgumentException|BadRequestException) {
                return $this->requestPage('Vérifiez le formulaire et réessayez depuis cette page.', 400);
            } catch (\DomainException) {
                return $this->requestPage('La demande entre en conflit avec votre compte. Rechargez la page et réessayez.', 409);
            }
            if (null === $receipt) {
                return $this->requestPage('Reconnectez-vous pour demander cette action.', 403);
            }

            return $this->statusPage($receipt->request, $receipt->statusToken, status: 202);
        }

        return $this->requestPage();
    }

    #[Route('/confidentialite/suivi', name: 'privacy_status', methods: ['GET', 'POST'])]
    public function status(Request $request, ManagePrivacyRequests $privacy): Response
    {
        if ($request->isMethod('GET')) {
            return $this->statusPage();
        }
        try {
            $token = $request->request->getString('status_token');
            $record = $privacy->status($token, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (BadRequestException) {
            return $this->statusPage(error: 'Indiquez le code de suivi reçu avec votre demande.', status: 400);
        } catch (\InvalidArgumentException|\DomainException) {
            return $this->statusPage(error: 'Ce code de suivi est expiré ou indisponible.', status: 404);
        } catch (\RuntimeException|\DateMalformedStringException) {
            return $this->statusPage(error: 'Le suivi est temporairement indisponible. Réessayez plus tard.', status: 503);
        }

        return $this->statusPage($record, $token);
    }

    private function requestPage(?string $error = null, int $status = 200): Response
    {
        $ids = [];
        foreach (['access_export', 'correction', 'deletion'] as $kind) {
            $ids[$kind] = Uuid::v7()->toRfc4122();
        }

        return $this->render('privacy/requests.html.twig', ['ids' => $ids, 'error' => $error], new Response(status: $status, headers: self::HEADERS));
    }

    private function statusPage(?PrivacyRequestRecord $record = null, string $token = '', ?string $error = null, int $status = 200): Response
    {
        return $this->render('privacy/status.html.twig', ['record' => $record, 'token' => $token, 'error' => $error], new Response(status: $status, headers: self::HEADERS));
    }
}
