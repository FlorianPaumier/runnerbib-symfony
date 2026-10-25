<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$environment = $_SERVER['APP_ENV'] ?? 'prod';
if (!in_array($environment, ['dev', 'test'], true)) {
    fwrite(STDERR, "Fixtures require APP_ENV=dev or test.\n");
    exit(1);
}
$password = $_SERVER['FIXTURE_PASSWORD'] ?? getenv('FIXTURE_PASSWORD');
if (!is_string($password) || strlen($password) < 12) {
    fwrite(STDERR, "Set FIXTURE_PASSWORD to a local password of at least 12 characters.\n");
    exit(1);
}
$kernel = new Kernel($environment, false);
$kernel->boot();
$doctrine = $kernel->getContainer()->get('doctrine');
$database = $doctrine->getConnection();
assert($database instanceof Doctrine\DBAL\Connection);
$sql = file_get_contents(dirname(__DIR__).'/fixtures/development.sql');
assert(is_string($sql));
$hash = password_hash($password, PASSWORD_ARGON2ID);
$proof = dirname(__DIR__).'/fixtures/demo-proof.pdf';
$directory = $_SERVER['PROOF_STORAGE_DIRECTORY'] ?? getenv('PROOF_STORAGE_DIRECTORY') ?: dirname(__DIR__).'/var/private/proofs';
assert(is_string($directory));
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create private fixture directory.');
}
foreach ([5, 6, 7] as $number) {
    $id = md5('demo:object:'.$number);
    $id = substr($id, 0, 8).'-'.substr($id, 8, 4).'-'.substr($id, 12, 4).'-'.substr($id, 16, 4).'-'.substr($id, 20);
    $destination = $directory.'/'.$id.'.blob';
    if (!file_exists($destination) && !copy($proof, $destination)) {
        throw new RuntimeException('Cannot install synthetic proof.');
    }
    if (is_link($destination) || hash_file('sha256', $proof) !== hash_file('sha256', $destination)) {
        throw new RuntimeException('Existing fixture proof differs; no file was overwritten.');
    }
    if (!chmod($destination, 0600)) {
        throw new RuntimeException('Cannot secure synthetic proof.');
    }
}
$sql = str_replace(['__PROOF_SIZE__', '__PROOF_CHECKSUM__'], [(string) filesize($proof), $database->quote((string) hash_file('sha256', $proof))], $sql);
try {
    $database->transactional(static function (Doctrine\DBAL\Connection $connection) use ($sql, $hash): void {
        $connection->executeStatement("SELECT pg_advisory_xact_lock(hashtext('runnerbib-development-fixtures'))");
        $connection->executeStatement(str_replace('__PASSWORD_HASH__', $connection->quote($hash), $sql));
    });
    fwrite(STDOUT, "Development fixtures loaded; existing records retained.\n");
} finally {
    $kernel->shutdown();
}
