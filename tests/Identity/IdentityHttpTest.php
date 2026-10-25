<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tests\Support\IsolatedWebTestCase;

final class IdentityHttpTest extends IsolatedWebTestCase
{
    public function testFrenchAccountFormsAndCsrfRejection(): void
    {
        $client = self::createClient();
        $limiter = self::getContainer()->get('limiter.identity_request');
        $limiter->create('127.0.0.1')->reset();
        foreach (['/inscription' => 'Créer un compte', '/connexion' => 'Connexion', '/mot-de-passe-oublie' => 'Réinitialiser le mot de passe'] as $path => $heading) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $heading);
            self::assertSelectorExists('input[name="_csrf_token"]');
        }
        $client->request('POST', '/inscription', ['email' => 'runner@example.test', 'password' => 'long enough secret', 'terms' => '1', '_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(400);
        $client->request('GET', '/verification/invalide');
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $client->request('GET', '/reinitialiser/invalide');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        $client->request('POST', '/reinitialiser/invalide', ['password' => 'short', '_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testSignupVerificationLoginAndPasswordRecovery(): void
    {
        $client = self::createClient();
        $limiter = self::getContainer()->get('limiter.identity_request');
        $limiter->create('127.0.0.1')->reset();
        $crawler = $client->request('GET', '/inscription');
        $client->submit($crawler->selectButton('Continuer')->form(['email' => 'runner@example.test', 'password' => 'correct horse battery staple', 'terms' => '1']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'Si cette adresse');
        $payload = $this->database->fetchOne('SELECT payload FROM outbox_messages');
        self::assertIsString($payload);
        $message = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($message);
        self::assertIsString($message['token']);
        $client->request('GET', '/verification/'.self::getContainer()->get(\App\Application\Operations\CredentialTokenProtector::class)->reveal($message['token']));
        self::assertResponseIsSuccessful();
        $crawler = $client->request('GET', '/connexion');
        $client->submit($crawler->selectButton('Continuer')->form(['_username' => 'runner@example.test', '_password' => 'correct horse battery staple']));
        self::assertResponseRedirects('/');
        $client->request('GET', '/compte');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mon compte');
        $crawler = $client->request('GET', '/mot-de-passe-oublie');
        $client->submit($crawler->selectButton('Continuer')->form(['email' => 'runner@example.test']));
        self::assertResponseIsSuccessful();
        $payloads = $this->database->fetchFirstColumn('SELECT payload FROM outbox_messages');
        $reset = null;
        foreach ($payloads as $value) {
            self::assertIsString($value);
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            if ('password_reset' === $decoded['purpose']) {
                self::assertIsString($decoded['token']);
                $reset = self::getContainer()->get(\App\Application\Operations\CredentialTokenProtector::class)->reveal($decoded['token']);
            }
        }
        self::assertIsString($reset);
        $crawler = $client->request('GET', '/reinitialiser/'.$reset);
        $client->submit($crawler->selectButton('Continuer')->form(['password' => 'another strong password']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'Mot de passe modifié');
        $client->request('GET', '/compte');
        self::assertResponseRedirects('/connexion');
    }

    public function testInvalidSignupAndRateLimitAndGenericRecovery(): void
    {
        $clientIp = '192.0.2.'.random_int(1, 254);
        $client = self::createClient([], ['REMOTE_ADDR' => $clientIp]);
        $limiter = self::getContainer()->get('limiter.identity_request');
        $limiter->create($clientIp)->reset();
        $crawler = $client->request('GET', '/inscription');
        $client->submit($crawler->selectButton('Continuer')->form(['email' => 'runner@example.test', 'password' => 'short', 'terms' => '1']));
        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('[role="status"]', 'au moins 12');
        $client->request('POST', '/mot-de-passe-oublie', ['email' => 'runner@example.test', '_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(400);
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $crawler = $client->request('GET', '/mot-de-passe-oublie');
            $client->submit($crawler->selectButton('Continuer')->form(['email' => 'missing@example.test']));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', 'Si un compte correspond');
        }
        $crawler = $client->request('GET', '/mot-de-passe-oublie');
        $client->submit($crawler->selectButton('Continuer')->form(['email' => 'missing@example.test']));
        self::assertResponseStatusCodeSame(429);
        $crawler = $client->request('GET', '/inscription');
        $client->submit($crawler->selectButton('Continuer')->form(['email' => 'runner@example.test', 'password' => 'correct horse battery staple', 'terms' => '1']));
        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM accounts'));
    }

    public function testPasswordResetSubmissionIsRateLimitedBeforePasswordHashing(): void
    {
        $clientIp = '198.18.'.random_int(0, 255).'.'.random_int(1, 254);
        $client = self::createClient([], ['REMOTE_ADDR' => $clientIp]);
        self::getContainer()->get('limiter.identity_request')->create($clientIp)->reset();
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $crawler = $client->request('GET', '/mot-de-passe-oublie');
            $client->submit($crawler->selectButton('Continuer')->form(['email' => 'missing@example.test']));
            self::assertResponseIsSuccessful();
        }
        $crawler = $client->request('GET', '/reinitialiser/synthetic-unused-token');
        $client->submit($crawler->selectButton('Continuer')->form(['password' => 'correct horse battery staple']));
        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM account_credentials'));
    }

    public function testVerificationRecoveryFormIsGenericAndCsrfProtected(): void
    {
        (new \App\Infrastructure\Identity\DbalIdentityStore($this->database))->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', 'hash', 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $client = self::createClient();
        self::getContainer()->get('limiter.identity_request')->create('127.0.0.1')->reset();
        $crawler = $client->request('GET', '/renvoyer-verification');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Renvoyer la vérification');
        self::assertSelectorNotExists('input[type="password"]');
        $client->request('POST', '/renvoyer-verification', ['email' => 'runner@example.test', '_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(400);
        $crawler = $client->request('GET', '/renvoyer-verification');
        $client->submit($crawler->selectButton('Continuer')->form(['email' => 'runner@example.test']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'Si une vérification est nécessaire');
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM account_credentials'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        $crawler = $client->request('GET', '/renvoyer-verification');
        $client->submit($crawler->selectButton('Continuer')->form(['email' => 'missing@example.test']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'Si une vérification est nécessaire');
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM account_credentials'));
    }

    public function testVerificationResendRateLimitCreatesNoCredentials(): void
    {
        $clientIp = '2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2)).'::1';
        $client = self::createClient([], ['REMOTE_ADDR' => $clientIp]);
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $crawler = $client->request('GET', '/renvoyer-verification');
            $client->submit($crawler->selectButton('Continuer')->form(['email' => 'missing@example.test']));
            self::assertResponseIsSuccessful();
        }
        $crawler = $client->request('GET', '/renvoyer-verification');
        $client->submit($crawler->selectButton('Continuer')->form(['email' => 'missing@example.test']));
        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM account_credentials'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
    }
}
