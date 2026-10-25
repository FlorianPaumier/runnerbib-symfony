<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Identity\CurrentIdentity;
use App\Application\Imports\ExecuteEligibilityImport;
use App\Application\Imports\PreviewEligibilityImport;
use App\Application\Organizations\OrganizationScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class OrganizerImportController extends AbstractController
{
    /** @param list<string>|null $mutableFields */
    public function __construct(private readonly CurrentIdentity $identity, private readonly OrganizationScope $scope, private readonly PreviewEligibilityImport $preview, private readonly ExecuteEligibilityImport $imports, private readonly ?int $maximumBytes = null, private readonly ?array $mutableFields = null)
    {
    }

    #[Route('/organisations/courses/{raceId}/import', name: 'organizer_import', requirements: ['raceId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET', 'POST'])]
    public function import(string $raceId, Request $request): Response
    {
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
        $accountId = $this->identity->accountId();
        if (null === $accountId) {
            return $this->redirectToRoute('identity_login');
        }
        try {
            $this->scope->forRace($accountId, $raceId);
        } catch (\DomainException) {
            return new Response('Accès à cette course non autorisé.', 403, $headers);
        }
        $view = ['raceId' => $raceId, 'importId' => Uuid::v7()->toRfc4122(), 'mutableFields' => $this->mutableFields ?? [], 'error' => null, 'rows' => [], 'stage' => null, 'mode' => 'skip_invalid', 'selectedFields' => [], 'enabled' => null !== $this->maximumBytes && $this->maximumBytes > 0 && PHP_INT_MAX !== $this->maximumBytes];
        $status = 200;
        if (null === $this->maximumBytes || $this->maximumBytes < 1 || PHP_INT_MAX === $this->maximumBytes) {
            $view['error'] = 'L’importation attend la configuration approuvée du format CSV et de ses limites.';
            $status = 503;
        } elseif ($request->isMethod('POST')) {
            $view['importId'] = $request->request->getString('import_id');
            if (!$this->isCsrfTokenValid('organizer_import_'.$raceId, $request->request->getString('_token'))) {
                return new Response('Action non autorisée.', 403, $headers);
            }
            try {
                $action = $request->request->getString('action');
                $view['mode'] = $request->request->getString('mode', 'skip_invalid');
                $fields = $request->request->all('champs');
                if (!Uuid::isValid($view['importId']) || !in_array($action, ['preview', 'execute'], true) || !in_array($view['mode'], ['skip_invalid', 'update'], true)) {
                    throw new \InvalidArgumentException('Invalid import request.');
                }
                foreach ($fields as $field) {
                    if (!is_string($field)) {
                        throw new \InvalidArgumentException('Invalid selected import field.');
                    }
                    $view['selectedFields'][] = $field;
                }
                if ('update' === $view['mode'] && null === $this->mutableFields) {
                    throw new \LogicException('Import update policy is not configured.');
                }
                $file = $request->files->get('csv');
                if (!$file instanceof UploadedFile || UPLOAD_ERR_OK !== $file->getError() || !$file->isValid() || !is_file($file->getPathname()) || is_link($file->getPathname())) {
                    throw new \InvalidArgumentException('Invalid CSV upload.');
                }
                $csv = @file_get_contents($file->getPathname(), false, null, 0, $this->maximumBytes + 1);
                if (false === $csv || strlen($csv) > $this->maximumBytes) {
                    throw new \InvalidArgumentException('CSV upload exceeds configured byte limit.');
                }
                $view['rows'] = 'preview' === $action ? $this->preview->parse($csv) : $this->imports->execute($accountId, $raceId, $view['importId'], $csv, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $view['mode'], $view['selectedFields']);
                $view['stage'] = $action;
            } catch (\DomainException) {
                $view['error'] = 'L’importation ne peut pas être enregistrée pour cette course ou cet identifiant.';
                $status = 409;
            } catch (\InvalidArgumentException|\Symfony\Component\HttpFoundation\Exception\BadRequestException) {
                $view['error'] = 'Vérifiez le fichier CSV, son format, ses limites et les champs sélectionnés.';
                $status = 400;
            } catch (\LogicException|\RuntimeException) {
                $view['error'] = 'L’importation attend une configuration approuvée ou est temporairement indisponible.';
                $status = 503;
            }
        }

        return $this->render('imports/organizer.html.twig', $view, new Response(status: $status, headers: $headers));
    }
}
