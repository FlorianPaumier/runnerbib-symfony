<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Infrastructure\Identity\IdentityUserProvider;
use App\Tests\Support\IsolatedWebTestCase;

final class NotificationPagesTest extends IsolatedWebTestCase
{
    public function testInboxUsesAuthenticatedRecipientAndCsrfProtectedRead(): void
    {
        $owner = '00000000-0000-0000-0000-000000000001';
        $other = '00000000-0000-0000-0000-000000000002';
        foreach ([$owner => 'owner@example.test', $other => 'other@example.test'] as $id => $email) {
            $this->database->insert('accounts', ['id' => $id, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-09Z', 'verified_at' => '2026-10-09Z']);
            $this->database->insert('account_private_profiles', ['account_id' => $id, 'email' => $email, 'email_normalized' => $email]);
        }
        $notice = '00000000-0000-0000-0000-000000000010';
        $foreign = '00000000-0000-0000-0000-000000000011';
        foreach ([$notice => $owner, $foreign => $other] as $id => $recipient) {
            $this->database->insert('notifications', ['id' => $id, 'recipient_id' => $recipient, 'event_key' => $id, 'category' => 'account', 'private_payload' => '{"secret":"must never appear"}', 'payload_version' => 1, 'created_at' => '2026-10-09Z', 'delivery_state' => '{"in_app":"accepted"}']);
        }
        $client = self::createClient();
        $client->request('GET', '/notifications');
        self::assertResponseRedirects('/connexion');
        $client->request('POST', '/notifications/'.$notice.'/lire');
        self::assertResponseRedirects('/connexion');
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('owner@example.test'));
        $crawler = $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Notifications');
        self::assertSelectorTextNotContains('main', 'must never appear');
        self::assertSelectorExists('form[action="/notifications/'.$notice.'/lire"]');
        self::assertSelectorNotExists('form[action="/notifications/'.$foreign.'/lire"]');
        $client->request('POST', '/notifications/'.$notice.'/lire', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $readForm = $crawler->selectButton('Marquer comme lue')->form();
        $field = $readForm['_token'];
        self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\FormField::class, $field);
        $token = $field->getValue();
        $client->submit($readForm);
        self::assertResponseRedirects('/notifications');
        self::assertNotNull($this->database->fetchOne('SELECT read_at FROM notifications WHERE id = ?', [$notice]));
        self::assertNull($this->database->fetchOne('SELECT read_at FROM notifications WHERE id = ?', [$foreign]));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'Lue');
        self::assertSelectorNotExists('button');
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        $this->database->update('notifications', ['recipient_id' => $other], ['id' => $notice]);
        $client->request('POST', '/notifications/'.$notice.'/lire', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->database->fetchOne('SELECT read_at FROM notifications WHERE id = ?', [$foreign]));
        $this->database->executeStatement('DELETE FROM notifications WHERE recipient_id = ?', [$owner]);
        $client->request('GET', '/notifications');
        self::assertSelectorTextContains('[role="status"]', 'aucune notification');
    }

    public function testAvailabilityNoticeShowsOnlyTheOfferProjection(): void
    {
        $owner = '00000000-0000-0000-0000-000000000001';
        $listing = '00000000-0000-0000-0000-000000000009';
        $this->database->insert('accounts', ['id' => $owner, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-09Z', 'verified_at' => '2026-10-09Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $owner, 'email' => 'owner@example.test', 'email_normalized' => 'owner@example.test']);
        $this->database->insert('notifications', ['id' => '00000000-0000-0000-0000-000000000010', 'recipient_id' => $owner, 'event_key' => 'availability:fixture', 'category' => 'availability', 'private_payload' => json_encode(['listing_id' => $listing, 'price_cents' => 10000, 'currency' => 'EUR', 'priority_ends_at' => '2026-10-10T11:00:00+00:00', 'email' => 'private@example.test'], JSON_THROW_ON_ERROR), 'payload_version' => 1, 'created_at' => '2026-10-10Z', 'delivery_state' => '{"in_app":"accepted"}']);
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('owner@example.test'));
        $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main a[href="/dossards/'.$listing.'"]');
        self::assertSelectorTextSame('main a[href="/dossards/'.$listing.'"]', 'Voir l’offre à 100,00 €');
        self::assertSelectorTextContains('main', '11:00');
        self::assertSelectorTextNotContains('main', 'private@example.test');
        $this->database->executeStatement('UPDATE notifications SET payload_version = 2');
        $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('main a[href="/dossards/'.$listing.'"]');
    }
}
