<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Infrastructure\Identity\DbalIdentityStore;
use App\Infrastructure\Identity\IdentityUserProvider;
use App\Infrastructure\Privacy\HmacPrivacyRequestTokens;
use App\Tests\Support\IsolatedWebTestCase;

final class PrivacyPagesTest extends IsolatedWebTestCase
{
    private const ACCOUNT = '00000000-0000-0000-0000-000000000001';
    private mixed $previousLifetime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousLifetime = $_SERVER['PRIVACY_PROGRESS_LIFETIME_SECONDS'] ?? null;
        $_SERVER['PRIVACY_PROGRESS_LIFETIME_SECONDS'] = $_ENV['PRIVACY_PROGRESS_LIFETIME_SECONDS'] = '3600';
        (new DbalIdentityStore($this->database))->register(self::ACCOUNT, 'privacy@example.test', 'fixture-hash', 'fixture-terms', new \DateTimeImmutable('2026-10-13Z'));
        $this->database->update('accounts', ['verified_at' => '2026-10-13Z'], ['id' => self::ACCOUNT]);
    }

    protected function tearDown(): void
    {
        if (null === $this->previousLifetime) {
            unset($_SERVER['PRIVACY_PROGRESS_LIFETIME_SECONDS'], $_ENV['PRIVACY_PROGRESS_LIFETIME_SECONDS']);
        } else {
            $_SERVER['PRIVACY_PROGRESS_LIFETIME_SECONDS'] = $_ENV['PRIVACY_PROGRESS_LIFETIME_SECONDS'] = $this->previousLifetime;
        }
        parent::tearDown();
    }

    public function testMalformedStoredProgressFailsClosedWithoutDisclosingTheReceipt(): void
    {
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('privacy@example.test'));
        $crawler = $client->request('GET', '/compte/confidentialite');
        $form = $crawler->selectButton('Demander l’accès à mes données')->form();
        $client->submit($form);
        self::assertResponseStatusCodeSame(202);
        $token = $client->getCrawler()->filter('textarea[name="status_token"]')->text();
        $requestId = $form->getPhpValues()['request_id'];
        self::assertIsString($requestId);
        $raw = $this->database->fetchOne('SELECT authority_snapshot::text FROM administrative_audit WHERE id=?', [$requestId]);
        self::assertIsString($raw);
        $original = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($original);
        foreach ([['account_version' => '1'], ['blockers' => [42]], ['authority_expires_at' => 'invalid-date']] as $index => $invalid) {
            $metadata = array_replace($original, $invalid, ['request_version' => $index + 2]);
            $this->database->insert('administrative_audit', ['id' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(), 'service_principal' => 'fixture-progress', 'authority_snapshot' => json_encode($metadata, JSON_THROW_ON_ERROR), 'action' => 'privacy.progressed', 'subject_kind' => 'privacy_request', 'subject_id' => $requestId, 'occurred_at' => '2026-10-13Z', 'outcome' => 'pending']);
            $client->request('POST', '/confidentialite/suivi', ['status_token' => $token]);
            self::assertResponseStatusCodeSame(503);
            self::assertSelectorTextContains('[role="alert"]', 'temporairement indisponible');
            self::assertSelectorNotExists('h2');
            self::assertSelectorTextNotContains('main', 'privacy@example.test');
            self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        }
        self::assertSame('active', $this->database->fetchOne('SELECT lifecycle_state FROM accounts'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        self::assertSame($raw, $this->database->fetchOne('SELECT authority_snapshot::text FROM administrative_audit WHERE id=?', [$requestId]));
    }

    public function testAuthenticatedRequestReturnsPendingReceiptAndBodyOnlyStatusCode(): void
    {
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('privacy@example.test'));
        $crawler = $client->request('GET', '/compte/confidentialite');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Demander l’accès à mes données')->form();
        $client->submit($form);
        self::assertResponseStatusCodeSame(202);
        self::assertSelectorTextContains('[role="status"]', 'Demande reçue');
        self::assertSelectorTextNotContains('main', 'privacy@example.test');
        $token = $client->getCrawler()->filter('textarea[name="status_token"]')->text();
        self::assertNotSame('', $token);
        self::assertSelectorNotExists('a[href*="'.$token.'"]');
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='privacy.requested'"));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='privacy.requested'"));
        $client->request('POST', '/confidentialite/suivi', ['status_token' => $token, 'state' => 'completed']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'Demande reçue');
        self::assertSelectorTextContains('main', 'en attente de traitement');
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        $client->submit($form);
        self::assertResponseStatusCodeSame(202);
        self::assertSame($token, $client->getCrawler()->filter('textarea[name="status_token"]')->text());
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='privacy.requested'"));
    }

    public function testDeletionCanBeTrackedAfterSessionRevocationWithoutClaimingAnonymization(): void
    {
        $client = self::createClient();
        $client->request('GET', '/compte/confidentialite');
        self::assertResponseRedirects('/connexion');
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('privacy@example.test'));
        $crawler = $client->request('GET', '/compte/confidentialite');
        self::assertSelectorTextContains('main', 'désactive votre compte');
        $client->submit($crawler->selectButton('Demander la suppression de mon compte')->form());
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['lifecycle_state' => 'suspended', 'version' => 2, 'anonymized_at' => null], $this->database->fetchAssociative('SELECT lifecycle_state,version,anonymized_at FROM accounts'));
        self::assertSame('privacy@example.test', $this->database->fetchOne('SELECT email FROM account_private_profiles'));
        $token = $client->getCrawler()->filter('textarea[name="status_token"]')->text();
        $client->request('GET', '/compte');
        self::assertResponseRedirects('/connexion');
        $client->request('POST', '/confidentialite/suivi', ['status_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Votre compte est désactivé');
        self::assertSelectorTextContains('main', 'en attente de traitement');
        self::assertSelectorTextNotContains('main', 'privacy@example.test');
        self::assertSelectorTextNotContains('main', 'retention_policy');
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
    }

    public function testUnconfiguredExpiryRefusesBeforeFencingAndForgeryDoesNotCreateRequests(): void
    {
        $_SERVER['PRIVACY_PROGRESS_LIFETIME_SECONDS'] = $_ENV['PRIVACY_PROGRESS_LIFETIME_SECONDS'] = '';
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('privacy@example.test'));
        $crawler = $client->request('GET', '/compte/confidentialite');
        $form = $crawler->selectButton('Demander la suppression de mon compte')->form();
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['_token' => 'forged']));
        self::assertResponseStatusCodeSame(403);
        $client->submit($form);
        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('[role="alert"]', 'Votre compte et vos annonces sont conservés');
        self::assertSame(['lifecycle_state' => 'active', 'version' => 1], $this->database->fetchAssociative('SELECT lifecycle_state,version FROM accounts'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
    }

    public function testInvalidAndConflictingRequestKindsCannotChangeRecordedPurpose(): void
    {
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('privacy@example.test'));
        $crawler = $client->request('GET', '/compte/confidentialite');
        $form = $crawler->selectButton('Demander l’accès à mes données')->form();
        foreach ([['kind' => 'purge'], ['request_id' => 'not-a-uuid'], ['kind' => ['deletion']]] as $values) {
            $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), $values));
            self::assertResponseStatusCodeSame(400);
        }
        $client->submit($form);
        self::assertResponseStatusCodeSame(202);
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['kind' => 'correction']));
        self::assertResponseStatusCodeSame(409);
        $crawler = $client->request('GET', '/compte/confidentialite');
        $client->submit($crawler->selectButton('Demander la rectification de mes données')->form());
        self::assertResponseStatusCodeSame(202);
        self::assertSelectorTextContains('h2', 'Rectification de mes données');
        self::assertSame(2, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='privacy.requested'"));
        self::assertSame('active', $this->database->fetchOne('SELECT lifecycle_state FROM accounts'));
    }

    public function testPublicStatusRejectsMalformedExpiredAndUnrelatedCodesWithoutPrivateData(): void
    {
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('privacy@example.test'));
        $crawler = $client->request('GET', '/compte/confidentialite');
        $form = $crawler->selectButton('Demander l’accès à mes données')->form();
        $requestId = $form->getPhpValues()['request_id'];
        self::assertIsString($requestId);
        $client->submit($form);
        self::assertResponseStatusCodeSame(202);
        $secret = $_SERVER['APP_SECRET'];
        self::assertIsString($secret);
        $tokens = new HmacPrivacyRequestTokens($secret);
        $invalidCodes = [
            'forged',
            $tokens->issue($requestId, self::ACCOUNT, 1, new \DateTimeImmutable('2000-01-01Z')),
            $tokens->issue($requestId, '00000000-0000-0000-0000-000000000002', 1, new \DateTimeImmutable('2040-01-01Z')),
            $tokens->issue('00000000-0000-0000-0000-000000000099', self::ACCOUNT, 1, new \DateTimeImmutable('2040-01-01Z')),
        ];
        $client->request('GET', '/confidentialite/suivi');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('textarea[name="status_token"]');
        foreach ($invalidCodes as $code) {
            $client->request('POST', '/confidentialite/suivi', ['status_token' => $code]);
            self::assertResponseStatusCodeSame(404);
            self::assertSelectorExists('[role="alert"]');
            self::assertSelectorNotExists('h2');
            self::assertSelectorTextNotContains('main', 'privacy@example.test');
            self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        }
        $client->request('POST', '/confidentialite/suivi', ['status_token' => ['forged']]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='privacy.requested'"));
    }
}
