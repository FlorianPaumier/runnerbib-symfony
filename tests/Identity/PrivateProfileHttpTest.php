<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Infrastructure\Identity\DbalIdentityStore;
use App\Infrastructure\Identity\SecurityUser;
use App\Tests\Support\IsolatedWebTestCase;

final class PrivateProfileHttpTest extends IsolatedWebTestCase
{
    /** @var array<array-key, mixed> */
    private array $previousServer = [];
    /** @var array<array-key, mixed> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->previousServer = $_SERVER;
        $this->previousEnvironment = $_ENV;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_SERVER = $this->previousServer;
        $_ENV = $this->previousEnvironment;
    }

    public function testUnconfiguredOptionalNotificationGrantLeavesProfileUnchanged(): void
    {
        $hash = password_hash('correct horse battery staple', PASSWORD_ARGON2ID);
        (new DbalIdentityStore($this->database))->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', $hash, 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $client = self::createClient();
        $client->loginUser(new SecurityUser('00000000-0000-0000-0000-000000000001', 'runner@example.test', $hash, ['runner'], 1));
        $crawler = $client->request('GET', '/compte');
        $client->submit($crawler->selectButton('Enregistrer')->form(['display_name' => 'Unaccepted change', 'optional_email_enabled' => '1', 'version' => '1']));
        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('[role="status"]', 'préférences');
        self::assertNull($this->database->fetchOne('SELECT display_name FROM account_private_profiles'));
        self::assertFalse($this->database->fetchOne('SELECT optional_email_enabled FROM accounts'));
        self::assertSame(1, $this->database->fetchOne('SELECT version FROM accounts'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testPrivateProfilePreferencesAreScopedAndPublicProfileOmitsEmail(): void
    {
        $_SERVER['OPTIONAL_NOTIFICATION_EMAIL_POLICY_VERSION'] = $_ENV['OPTIONAL_NOTIFICATION_EMAIL_POLICY_VERSION'] = 'test-only-email-v1';
        $_SERVER['OPTIONAL_NOTIFICATION_IN_APP_POLICY_VERSION'] = $_ENV['OPTIONAL_NOTIFICATION_IN_APP_POLICY_VERSION'] = 'test-only-in-app-v1';
        $hash = password_hash('correct horse battery staple', PASSWORD_ARGON2ID);
        $store = new DbalIdentityStore($this->database);
        $store->register('00000000-0000-0000-0000-000000000001', 'runner@example.test', $hash, 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $store->register('00000000-0000-0000-0000-000000000002', 'other@example.test', $hash, 'v1', new \DateTimeImmutable('2026-10-09Z'));
        $client = self::createClient();
        $client->loginUser(new SecurityUser('00000000-0000-0000-0000-000000000001', 'runner@example.test', $hash, ['runner'], 1));
        $crawler = $client->request('GET', '/compte');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        self::assertSelectorTextContains('[data-private-email]', 'runner@example.test');
        self::assertSelectorExists('input[name="display_name"]');
        self::assertSelectorExists('input[name="optional_email_enabled"]');
        self::assertSelectorExists('input[name="optional_in_app_enabled"]');
        self::assertSelectorNotExists('input[name="email"]');
        $client->request('POST', '/compte', ['display_name' => 'Rejected', 'optional_email_enabled' => '1', 'optional_in_app_enabled' => '1', 'version' => '1', '_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(400);
        $crawler = $client->request('GET', '/compte');
        $token = $crawler->filter('form[data-profile] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/compte', ['display_name' => '  Runner  ', 'optional_email_enabled' => '1', 'optional_in_app_enabled' => '1', 'version' => '1', 'account_id' => '00000000-0000-0000-0000-000000000002', '_csrf_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'Profil enregistré');
        self::assertSame('Runner', $this->database->fetchOne("SELECT display_name FROM account_private_profiles WHERE account_id='00000000-0000-0000-0000-000000000001'"));
        self::assertNull($this->database->fetchOne("SELECT display_name FROM account_private_profiles WHERE account_id='00000000-0000-0000-0000-000000000002'"));
        self::assertTrue($this->database->fetchOne("SELECT optional_in_app_enabled FROM accounts WHERE id='00000000-0000-0000-0000-000000000001'"));
        self::assertTrue($this->database->fetchOne("SELECT optional_email_enabled FROM accounts WHERE id='00000000-0000-0000-0000-000000000001'"));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='account.profile_updated'"));
        $client->loginUser(new SecurityUser('00000000-0000-0000-0000-000000000001', 'runner@example.test', $hash, ['runner'], 2));
        $crawler = $client->request('GET', '/compte');
        $client->submit($crawler->selectButton('Enregistrer')->form(['display_name' => 'Stale overwrite', 'version' => '1']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='account.profile_updated'"));
        $client->request('GET', '/coureurs/00000000-0000-0000-0000-000000000001');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Runner');
        self::assertStringNotContainsString('runner@example.test', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('other@example.test', (string) $client->getResponse()->getContent());
        $client->request('GET', '/coureurs/00000000-0000-0000-0000-000000000099');
        self::assertResponseStatusCodeSame(404);
    }
}
