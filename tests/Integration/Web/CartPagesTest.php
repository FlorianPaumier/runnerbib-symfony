<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Application\Checkout\ManageCart;
use App\Infrastructure\Identity\IdentityUserProvider;
use App\Tests\Integration\Availability\AvailabilityTestCase;

final class CartPagesTest extends AvailabilityTestCase
{
    public function testCartDisplaysAuthoritativeItemFeesAndRetainsUnavailableItemsUntilRemoved(): void
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test']);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $client = self::createClient();
        $client->request('GET', '/panier');
        self::assertResponseRedirects('/connexion');
        $client->request('POST', '/panier/'.self::LISTING.'/ajouter');
        self::assertResponseRedirects('/connexion');
        $client->request('POST', '/panier/'.self::LISTING.'/retirer');
        self::assertResponseRedirects('/connexion');
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('buyer@example.test'));
        $client->request('GET', '/panier');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'vide');
        self::getContainer()->get(ManageCart::class)->add(self::MEMBER, self::LISTING, new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        $crawler = $client->request('GET', '/panier');
        self::assertSelectorTextContains('main', '100,00');
        self::assertSelectorTextContains('main', '5,00');
        self::assertSelectorTextContains('main', '105,00');
        $this->database->update('resale_listings', ['state' => 'withdrawn', 'withdrawn_at' => '2026-10-10Z'], ['id' => self::LISTING]);
        $crawler = $client->request('GET', '/panier');
        self::assertSelectorTextContains('main', 'indisponible');
        self::assertSelectorNotExists('form[action="/panier/payer"]');
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM cart_items'));
        $client->request('POST', '/panier/'.$this::LISTING.'/retirer', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $client->submit($crawler->selectButton('Retirer du panier')->form());
        self::assertResponseRedirects('/panier');
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM cart_items'));
    }
}
