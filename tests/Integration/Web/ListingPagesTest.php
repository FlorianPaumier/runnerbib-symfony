<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Infrastructure\Identity\IdentityUserProvider;
use App\Tests\Integration\Availability\AvailabilityTestCase;

final class ListingPagesTest extends AvailabilityTestCase
{
    public function testPublishedOfferShowsPricesAccessAndCsrfProtectedCartAction(): void
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test']);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $client = self::createClient();
        $client->request('GET', '/dossards/'.self::LISTING);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Paris Marathon');
        self::assertSelectorTextContains('main', '100,00');
        self::assertSelectorTextContains('main', '5,00');
        self::assertSelectorTextContains('main', '105,00');
        self::assertSelectorNotExists('button');
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('buyer@example.test'));
        $crawler = $client->request('GET', '/dossards/'.self::LISTING);
        $client->request('POST', '/panier/'.self::LISTING.'/ajouter', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $client->submit($crawler->selectButton('Ajouter au panier')->form());
        self::assertResponseRedirects('/panier');
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM cart_items'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM checkout_items'));
        $client->submit($crawler->selectButton('Ajouter au panier')->form());
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM cart_items'));
        $this->database->update('resale_listings', ['state' => 'withdrawn', 'withdrawn_at' => '2026-10-10Z'], ['id' => self::LISTING]);
        $client->request('GET', '/dossards/'.self::LISTING);
        self::assertSelectorTextContains('[role="status"]', 'indisponible');
        self::assertSelectorNotExists('button');
        $client->request('GET', '/dossards/00000000-0000-0000-0000-000000000099');
        self::assertResponseStatusCodeSame(404);
        $this->database->update('races', ['publication_state' => 'draft'], ['id' => self::RACE]);
        $client->request('GET', '/dossards/'.self::LISTING);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnquotableStoredOfferIsNotExposedAsPurchasable(): void
    {
        $this->seed();
        $this->database->update('resale_listings', ['price_cents' => PHP_INT_MAX], ['id' => self::LISTING]);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        self::createClient()->request('GET', '/dossards/'.self::LISTING);
        self::assertResponseStatusCodeSame(404);
    }
}
