<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TestLoggingTest extends TestCase
{
    public function testExpectedHttpErrorsDoNotWriteToStderr(): void
    {
        $process = new Process(['php', '-r', <<<'PHP'
            require 'vendor/autoload.php';
            (new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
            $kernel = new App\Kernel('test', true);
            $response = $kernel->handle(Symfony\Component\HttpFoundation\Request::create('/missing-logging-test-route'));
            echo $response->getStatusCode();
            PHP], dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $process->mustRun();

        self::assertSame('404', $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
        $log = file_get_contents(dirname(__DIR__, 2).'/var/test.log');
        self::assertIsString($log);
        self::assertStringContainsString('/missing-logging-test-route', $log);
    }
}
