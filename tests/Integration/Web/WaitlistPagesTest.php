<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Infrastructure\Identity\IdentityUserProvider;
use App\Tests\Support\IsolatedWebTestCase;

final class WaitlistPagesTest extends IsolatedWebTestCase
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

    public function testAuthenticatedRunnerJoinsUpdatesAndLeavesTheirOwnWaitlist(): void
    {
        $_SERVER['RACE_NOTIFICATION_EMAIL_POLICY_VERSION'] = $_ENV['RACE_NOTIFICATION_EMAIL_POLICY_VERSION'] = 'test-only-race-email-v1';
        $_SERVER['RACE_NOTIFICATION_IN_APP_POLICY_VERSION'] = $_ENV['RACE_NOTIFICATION_IN_APP_POLICY_VERSION'] = 'test-only-race-in-app-v1';
        $account = '00000000-0000-0000-0000-000000000001';
        $race = '00000000-0000-0000-0000-000000000010';
        $this->database->insert('accounts', ['id' => $account, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-10Z', 'verified_at' => '2026-10-10Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $account, 'email' => 'runner@example.test', 'email_normalized' => 'runner@example.test']);
        $this->database->insert('races', ['id' => $race, 'name' => 'Course de Paris', 'starts_at' => '2027-04-04Z', 'timezone' => 'Europe/Paris', 'sport_code' => 'running', 'distance_metres' => 10000, 'city' => 'Paris', 'country_code' => 'FR', 'publication_state' => 'published', 'created_at' => '2026-10-10Z', 'updated_at' => '2026-10-10Z']);
        $client = self::createClient();
        $client->request('GET', '/courses/'.$race.'/alertes');
        self::assertResponseRedirects('/connexion');
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('runner@example.test'));
        $crawler = $client->request('GET', '/courses/'.$race.'/alertes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Alertes');
        $client->request('POST', '/courses/'.$race.'/alertes', ['_token' => 'invalid', 'action' => 'join']);
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/courses/'.$race.'/alertes');
        $form = $crawler->selectButton('Rejoindre la liste')->form();
        $form['email_enabled'] = '1';
        $client->submit($form);
        self::assertResponseRedirects('/courses/'.$race.'/alertes');
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM race_waitlist_memberships WHERE account_id = ? AND ended_at IS NULL', [$account]));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'Vous êtes inscrit');
        $form = $crawler->selectButton('Enregistrer les préférences')->form();
        $emailField = $form['email_enabled'];
        self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $emailField);
        $emailField->untick();
        $form['in_app_enabled'] = '1';
        $client->submit($form);
        self::assertResponseRedirects('/courses/'.$race.'/alertes');
        self::assertFalse($this->database->fetchOne('SELECT email_enabled FROM race_waitlist_memberships WHERE account_id = ?', [$account]));
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Quitter la liste')->form());
        self::assertResponseRedirects('/courses/'.$race.'/alertes');
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM race_waitlist_memberships WHERE account_id = ? AND ended_at IS NULL', [$account]));
        $crawler = $client->followRedirect();
        $form = $crawler->selectButton('Rejoindre la liste')->form();
        $values = $form->getPhpValues();
        $values['action'] = 'forged';
        $client->request('POST', '/courses/'.$race.'/alertes', $values);
        self::assertResponseStatusCodeSame(400);
        $this->database->update('accounts', ['verified_at' => null], ['id' => $account]);
        $client->request('GET', '/courses/'.$race.'/alertes');
        self::assertResponseStatusCodeSame(403);
        $this->database->update('races', ['publication_state' => 'draft'], ['id' => $race]);
        $client->request('GET', '/courses/'.$race.'/alertes');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnconfiguredAlertGrantDoesNotPreventMembershipWithoutAlerts(): void
    {
        $account = '00000000-0000-0000-0000-000000000001';
        $race = '00000000-0000-0000-0000-000000000010';
        $this->database->insert('accounts', ['id' => $account, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-10Z', 'verified_at' => '2026-10-10Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $account, 'email' => 'runner@example.test', 'email_normalized' => 'runner@example.test']);
        $this->database->insert('races', ['id' => $race, 'name' => 'Course de Paris', 'starts_at' => '2027-04-04Z', 'timezone' => 'Europe/Paris', 'sport_code' => 'running', 'distance_metres' => 10000, 'city' => 'Paris', 'country_code' => 'FR', 'publication_state' => 'published', 'created_at' => '2026-10-10Z', 'updated_at' => '2026-10-10Z']);
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('runner@example.test'));
        $crawler = $client->request('GET', '/courses/'.$race.'/alertes');
        $client->submit($crawler->selectButton('Rejoindre la liste')->form(['email_enabled' => '1']));
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('préférences', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM race_waitlist_memberships'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        $client->submit($crawler->selectButton('Rejoindre la liste')->form());
        self::assertResponseRedirects('/courses/'.$race.'/alertes');
        self::assertSame(['email_enabled' => false, 'in_app_enabled' => false], $this->database->fetchAssociative('SELECT email_enabled,in_app_enabled FROM race_waitlist_memberships'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }
}
