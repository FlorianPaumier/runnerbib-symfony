<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Races\DiscoverRaces;
use App\Application\Races\FindPublicRace;
use App\Application\Races\RaceCursor;
use App\Application\Races\RaceSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RaceController extends AbstractController
{
    #[Route('/courses', name: 'races', methods: ['GET'])]
    public function index(Request $request, DiscoverRaces $discovery): Response
    {
        $name = $request->query->getString('nom');
        try {
            $page = $discovery->search(new RaceSearch(
                name: '' === $name ? null : $name,
                startsFrom: $this->date($request, 'debut'),
                startsUntil: $this->date($request, 'fin')?->modify('+1 day -1 microsecond'),
                sportCode: $this->text($request, 'sport'),
                city: $this->text($request, 'ville'),
                countryCode: $this->text($request, 'pays'),
                minDistanceMetres: $this->integer($request, 'distance_min'),
                maxDistanceMetres: $this->integer($request, 'distance_max'),
                cursor: null === ($cursor = $this->text($request, 'page')) ? null : RaceCursor::fromToken($cursor),
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->render('races/error.html.twig', [], new Response(status: 400));
        }

        return $this->render('races/index.html.twig', [
            'page' => $page, 'name' => $name,
            'filters' => $request->query->all(),
            'nextUrl' => null === $page->nextCursor ? null : $this->generateUrl('races', array_replace($request->query->all(), ['page' => $page->nextCursor])),
        ]);
    }

    #[Route('/courses/{id}', name: 'race_detail', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function detail(string $id, FindPublicRace $query): Response
    {
        $race = $query->get($id);
        if (null === $race) {
            return $this->render('races/not-found.html.twig', [], new Response(status: 404));
        }

        return $this->render('races/detail.html.twig', ['race' => $race]);
    }

    private function text(Request $request, string $key): ?string
    {
        $value = trim($request->query->getString($key));

        return '' === $value ? null : $value;
    }

    private function integer(Request $request, string $key): ?int
    {
        $value = $this->text($request, $key);
        if (null === $value) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (false === $integer) {
            throw new \InvalidArgumentException('Invalid integer filter.');
        }

        return $integer;
    }

    private function date(Request $request, string $key): ?\DateTimeImmutable
    {
        $value = $this->text($request, $key);
        if (null === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid date filter.');
        }

        return $date;
    }
}
