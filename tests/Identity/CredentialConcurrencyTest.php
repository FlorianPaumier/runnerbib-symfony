<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Application\Identity\RegisterAccount;
use App\Infrastructure\Identity\DbalIdentityStore;
use App\Infrastructure\Identity\OutboxIdentityEffects;
use App\Infrastructure\Identity\SecurityUser;
use App\Infrastructure\Operations\DbalAuditWriter;
use App\Infrastructure\Operations\DbalOutboxWriter;
use App\Infrastructure\Operations\DbalTransactionManager;
use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\Process\Process;

final class CredentialConcurrencyTest extends IsolatedWebTestCase
{
    public function testConcurrentSuspensionPreventsCredentialAndMailAfterActiveSnapshot(): void
    {
        $identities = new DbalIdentityStore($this->database);
        $identities->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $name = 'credential_'.bin2hex(random_bytes(6));
        $this->database->executeQuery("SELECT set_config('application_name', ?, false)", [$name]);
        $code = <<<'CODE'
require 'vendor/autoload.php';
$database = \Doctrine\DBAL\DriverManager::getConnection(json_decode(getenv('IDENTITY_TEST_PARAMS'), true, 512, JSON_THROW_ON_ERROR));
$database->beginTransaction();
$database->executeStatement("UPDATE accounts SET lifecycle_state='suspended',version=version+1 WHERE id='00000000-0000-0000-0000-000000000001'");
echo 'locked';
flush();
$deadline = microtime(true) + 5;
do {
    $database->executeQuery('SELECT pg_stat_clear_snapshot()');
    $blocked = $database->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [getenv('IDENTITY_PARENT_NAME')]);
    if (1 === $blocked) {
        $database->commit();
        echo 'released';
        exit(0);
    }
    usleep(20000);
} while (microtime(true) < $deadline);
$database->rollBack();
fwrite(STDERR, 'Credential issuance did not reach the account lock.');
exit(1);
CODE;
        $process = new Process(['php', '-r', $code], dirname(__DIR__, 2), ['IDENTITY_TEST_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR), 'IDENTITY_PARENT_NAME' => $name]);
        try {
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'locked')));
            $effects = new OutboxIdentityEffects(new DbalOutboxWriter($this->database), new PasswordHasherFactory([SecurityUser::class => ['algorithm' => 'argon2id']]), new \App\Infrastructure\Operations\AesCredentialTokenProtector('credential-token-secret-for-tests-0123456789'));
            (new RegisterAccount($identities, $effects, new DbalTransactionManager($this->database), new DbalAuditWriter($this->database)))->requestReset('runner@example.test');
            self::assertSame(0, $process->wait());
            self::assertSame('lockedreleased', $process->getOutput());
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM account_credentials'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        } finally {
            $process->stop();
        }
    }
}
