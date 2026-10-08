<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReadinessTest extends WebTestCase
{
    public function testReadinessChecksRealDatabaseConnection(): void
    {
        $client = self::createClient();
        $client->request('GET', '/ready');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertSame(['status' => 'ready'], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
    }
}
