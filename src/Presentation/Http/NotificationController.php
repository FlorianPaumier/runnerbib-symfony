<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Identity\CurrentIdentity;
use App\Application\Notifications\RecipientInbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationController extends AbstractController
{
    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    public function index(CurrentIdentity $identity, RecipientInbox $inbox): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }

        return $this->render('notifications/index.html.twig', ['messages' => $inbox->messages($accountId)], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/notifications/{id}/lire', name: 'notification_read', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function read(string $id, Request $request, CurrentIdentity $identity, RecipientInbox $inbox): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }
        if (!$this->isCsrfTokenValid('notification_read_'.$id, $request->request->getString('_token'))) {
            return new Response('Action non autorisée.', 403);
        }
        try {
            $inbox->read($accountId, $id, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\DomainException) {
            return new Response('Notification indisponible.', 404);
        }

        return $this->redirectToRoute('notifications');
    }
}
