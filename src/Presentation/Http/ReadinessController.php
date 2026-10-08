<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Health\CheckReadiness;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[AutoconfigureTag('controller.service_arguments')]
final readonly class ReadinessController
{
    public function __construct(private CheckReadiness $check)
    {
    }

    #[Route('/ready', name: 'ready', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $ready = ($this->check)();

        return new JsonResponse(['status' => $ready ? 'ready' : 'unavailable'], $ready ? 200 : 503);
    }
}
