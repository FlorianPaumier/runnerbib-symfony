<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class IsolatedWebTestCase extends WebTestCase
{
    protected Connection $database;
    private Connection $admin;
    private string $databaseName;
    private string $originalUrl;

    protected function stringifiedDatabase(): Connection
    {
        $parameters = $this->database->getParams();
        $parameters['driverOptions'][\PDO::ATTR_STRINGIFY_FETCHES] = true;
        $connection = DriverManager::getConnection($parameters);
        self::assertSame('1', $connection->fetchOne('SELECT 1'));

        return $connection;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = self::getContainer()->get(Connection::class);
        $url = $_SERVER['DATABASE_URL'] ?? null;
        self::assertIsString($url);
        $this->originalUrl = $url;
        self::ensureKernelShutdown();
        $this->databaseName = 'web_test_'.bin2hex(random_bytes(6));
        IsolatedDatabase::create($this->admin, $this->databaseName, dirname(__DIR__, 2), $this->originalUrl);
        $parameters = $this->admin->getParams();
        $parameters['dbname'] = $this->databaseName;
        $this->database = DriverManager::getConnection($parameters);
        $url = preg_replace('~/[^/?]+(?=\\?|$)~', '/'.$this->databaseName, $this->originalUrl);
        self::assertIsString($url);
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $url;
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $this->originalUrl;
        $this->database->close();
        $this->admin->executeStatement('DROP DATABASE '.$this->databaseName.' WITH (FORCE)');
        $this->admin->close();
        parent::tearDown();
    }
}
