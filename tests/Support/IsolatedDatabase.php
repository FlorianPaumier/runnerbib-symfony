<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Component\Process\Process;

/**
 * Gives every test its own database, copied from a migrated template.
 *
 * The template is migrated once per test process, so a test only pays for a database copy.
 */
final class IsolatedDatabase
{
    private static ?string $template = null;

    public static function create(Connection $admin, string $name, string $projectDir, string $url): void
    {
        $admin->executeStatement('CREATE DATABASE '.$name.' TEMPLATE '.self::template($admin, $projectDir, $url));
    }

    public static function drop(Connection $admin, string $name): void
    {
        $admin->executeStatement('DROP DATABASE IF EXISTS '.$name.' WITH (FORCE)');
    }

    private static function template(Connection $admin, string $projectDir, string $url): string
    {
        if (null !== self::$template) {
            return self::$template;
        }
        $name = 'runnerbib_test_template_'.getmypid();
        self::drop($admin, $name);
        $admin->executeStatement('CREATE DATABASE '.$name);
        $isolated = preg_replace('~/[^/?]+(?=\\?|$)~', '/'.$name, $url);
        if (!is_string($isolated)) {
            throw new \RuntimeException('The database url could not be rewritten.');
        }
        (new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], $projectDir, ['DATABASE_URL' => $isolated, 'APP_ENV' => 'test']))->setTimeout(300)->mustRun();
        self::$template = $name;
        $parameters = $admin->getParams();
        register_shutdown_function(static function () use ($parameters, $name): void {
            $connection = \Doctrine\DBAL\DriverManager::getConnection($parameters);
            try {
                $connection->executeStatement('DROP DATABASE IF EXISTS '.$name.' WITH (FORCE)');
            } catch (\Throwable) {
            }
            $connection->close();
        });

        return $name;
    }
}
