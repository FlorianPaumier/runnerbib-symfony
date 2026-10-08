<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DatabaseUnavailableTest extends WebTestCase
{
    public function testReadinessReturnsServiceUnavailableWithoutDatabase(): void
    {
        $originalServer = $_SERVER['DATABASE_URL'] ?? null;
        $originalEnv = $_ENV['DATABASE_URL'] ?? null;
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'postgresql://app:app@127.0.0.1:1/app?serverVersion=17';

        try {
            $client = self::createClient();
            $client->request('GET', '/ready');

            self::assertSame(503, $client->getResponse()->getStatusCode());
            $content = $client->getResponse()->getContent();
            self::assertIsString($content);
            self::assertSame(['status' => 'unavailable'], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            self::ensureKernelShutdown();
            if (null === $originalServer) {
                unset($_SERVER['DATABASE_URL']);
            } else {
                $_SERVER['DATABASE_URL'] = $originalServer;
            }
            if (null === $originalEnv) {
                unset($_ENV['DATABASE_URL']);
            } else {
                $_ENV['DATABASE_URL'] = $originalEnv;
            }
        }
    }
}
