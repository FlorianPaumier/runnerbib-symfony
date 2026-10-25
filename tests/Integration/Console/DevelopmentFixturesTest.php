<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\Process\Process;

final class DevelopmentFixturesTest extends IsolatedWebTestCase
{
    public function testFixturesAreRepeatableAndProvideUsableAccountsAndOffers(): void
    {
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);
        $environment = ['APP_ENV' => 'test', 'DATABASE_URL' => $url, 'FIXTURE_PASSWORD' => 'Fixture-test-password-42'];
        $process = new Process(['php', 'tools/load-dev-fixtures.php'], dirname(__DIR__, 3), $environment);
        $process->mustRun();
        self::assertStringContainsString('Development fixtures loaded', $process->getOutput());
        $process->mustRun();
        self::assertEquals(10, $this->database->fetchOne('SELECT count(*) FROM accounts'));
        self::assertEquals(2, $this->database->fetchOne('SELECT count(*) FROM organizations'));
        self::assertEquals(8, $this->database->fetchOne('SELECT count(*) FROM races'));
        self::assertEquals(10, $this->database->fetchOne('SELECT count(*) FROM resale_listings'));
        foreach ($this->database->fetchFirstColumn('SELECT registration_schema::text FROM races') as $definition) {
            self::assertIsString($definition);
            $schema = json_decode($definition, flags: JSON_THROW_ON_ERROR);
            self::assertInstanceOf(\stdClass::class, $schema);
            self::assertSame([], (new \App\Domain\Registration\RegistrationSchema($schema))->violations(new \stdClass()));
        }
        $hash = $this->database->fetchOne("SELECT a.password_hash FROM accounts a JOIN account_private_profiles p ON p.account_id=a.id WHERE p.email_normalized='admin@example.test'");
        self::assertIsString($hash);
        self::assertTrue(password_verify('Fixture-test-password-42', $hash));
        $client = self::createClient();
        $crawler = $client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Continuer')->form(['_username' => 'admin@example.test', '_password' => 'Fixture-test-password-42']);
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorExists('a[href="/compte"]');
        $client->request('GET', '/');
        self::assertSelectorCount(6, '.offer-card');
        $buyerId = $this->database->fetchOne("SELECT id::text FROM accounts WHERE id=md5('demo:account:buyer')::uuid");
        self::assertIsString($buyerId);
        $client->request('GET', '/admin/comptes/'.$buyerId.'/statut');
        self::assertResponseIsSuccessful();
        $proofId = $this->database->fetchOne("SELECT id::text FROM ownership_proofs WHERE id=md5('demo:proof:5')::uuid");
        self::assertIsString($proofId);
        $provider = self::getContainer()->get(\App\Infrastructure\Identity\IdentityUserProvider::class);
        foreach ([
            ['organizer@example.test', '/organisations', 'Collectif Horizons'],
            ['manager@example.test', '/organisations', 'Collectif Horizons'],
            ['user@example.test', '/organisations', 'Club des Sentiers'],
            ['seller@example.test', '/compte/ventes', 'Les 10 km des Quais'],
            ['buyer@example.test', '/panier', '33,00 €'],
        ] as [$email, $path, $expected]) {
            $client->loginUser($provider->loadUserByIdentifier($email));
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', $expected);
        }
        $client->loginUser($provider->loadUserByIdentifier('admin@example.test'));
        $previous = $_SERVER['PROOF_MAXIMUM_BYTES'] ?? null;
        $_SERVER['PROOF_MAXIMUM_BYTES'] = $_ENV['PROOF_MAXIMUM_BYTES'] = '1048576';
        try {
            $client->request('GET', '/admin/preuves/'.$proofId.'/document');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        } finally {
            if (null === $previous) {
                unset($_SERVER['PROOF_MAXIMUM_BYTES'], $_ENV['PROOF_MAXIMUM_BYTES']);
            } else {
                $_SERVER['PROOF_MAXIMUM_BYTES'] = $_ENV['PROOF_MAXIMUM_BYTES'] = $previous;
            }
        }
    }

    public function testLoaderRefusesProductionAndMissingPassword(): void
    {
        $process = new Process(['php', 'tools/load-dev-fixtures.php'], dirname(__DIR__, 3), ['APP_ENV' => 'prod', 'FIXTURE_PASSWORD' => 'Fixture-test-password-42']);
        $process->run();
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('dev or test', $process->getErrorOutput());
        $process = new Process(['php', 'tools/load-dev-fixtures.php'], dirname(__DIR__, 3), ['APP_ENV' => 'test', 'FIXTURE_PASSWORD' => '']);
        $process->run();
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('FIXTURE_PASSWORD', $process->getErrorOutput());
    }
}
