<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthEndpointReportsLiveness(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertSame(['status' => 'ok'], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
    }
}
