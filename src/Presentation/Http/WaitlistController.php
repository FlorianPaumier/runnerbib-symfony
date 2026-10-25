<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Availability\ManageRaceWaitlist;
use App\Application\Availability\UnconfiguredRaceNotificationPolicy;
use App\Application\Identity\CurrentIdentity;
use App\Application\Races\FindPublicRace;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class WaitlistController extends AbstractController
{
    #[Route('/courses/{id}/alertes', name: 'race_alerts', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET', 'POST'])]
    public function alerts(string $id, Request $request, CurrentIdentity $identity, FindPublicRace $races, ManageRaceWaitlist $waitlist): Response
    {
        $accountId = $identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }
        $race = $races->get($id);
        if (null === $race) {
            return $this->render('races/not-found.html.twig', [], new Response(status: 404));
        }
        try {
            $membership = $waitlist->mine($accountId, $id);
            if ($request->isMethod('POST')) {
                if (!$this->isCsrfTokenValid('race_alerts_'.$id, $request->request->getString('_token'))) {
                    return new Response('Action non autorisée.', 403);
                }
                $action = $request->request->getString('action');
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $email = $request->request->getBoolean('email_enabled');
                $inApp = $request->request->getBoolean('in_app_enabled');
                if ('join' === $action) {
                    $waitlist->join($accountId, $id, Uuid::v4()->toRfc4122(), $email, $inApp, $now);
                } elseif (null !== $membership && 'preferences' === $action) {
                    $waitlist->preferences($accountId, $membership->id, $email, $inApp);
                } elseif (null !== $membership && 'leave' === $action) {
                    $waitlist->leave($accountId, $membership->id, $now);
                } else {
                    return new Response('Action indisponible.', 400);
                }

                return $this->redirectToRoute('race_alerts', ['id' => $id]);
            }
        } catch (UnconfiguredRaceNotificationPolicy) {
            return new Response('Ces préférences ne peuvent pas être activées pour le moment. Vous pouvez rejoindre la liste sans activer les alertes.', 400, ['Cache-Control' => 'private, no-store']);
        } catch (\DomainException) {
            return new Response('Un compte actif et vérifié est nécessaire.', 403);
        }

        return $this->render('waitlist/index.html.twig', ['race' => $race, 'membership' => $membership], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }
}
