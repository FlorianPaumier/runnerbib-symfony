<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Availability\AvailabilityTestCase;

final class HomeTest extends AvailabilityTestCase
{
    public function testFrenchHomeHasAccessibleNavigationAndAssets(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorTextContains('h1', 'Reprenez');
        self::assertSelectorExists('a[href="/courses"]');
        self::assertSelectorExists('a[href="#contenu"]');
        self::assertSelectorExists('meta[name="viewport"]');
        self::assertSelectorExists('script[type="importmap"]');
        self::assertSelectorExists('[data-controller="landing"]');
        self::assertSelectorTextContains('#courses [role="status"]', 'Aucun dossard');
        self::assertSelectorNotExists('.offer-card');
    }

    public function testHomeShowsRealOfferAmountsAndHidesWithdrawnListings(): void
    {
        $this->seed();
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $client = self::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.offer-card');
        self::assertSelectorTextContains('.offer-card', 'Paris Marathon');
        self::assertSelectorTextContains('.offer-card', '105,00');
        self::assertSelectorTextContains('.offer-card', '100,00');
        self::assertSelectorTextContains('.offer-card', '5,00');
        self::assertSelectorExists('.offer-card a[href="/dossards/'.self::LISTING.'"]');
        self::assertSelectorTextContains('.offer-card', 'Accès public');
        self::assertStringNotContainsString('original@example.test', (string) $client->getResponse()->getContent());

        $this->database->update('availability_events', ['invalidated_at' => '2026-10-11T00:00:00Z', 'invalidation_reason' => 'withdrawn'], ['id' => self::EVENT]);
        $client->request('GET', '/');
        self::assertSelectorNotExists('.offer-card');
        $this->database->update('resale_listings', ['state' => 'withdrawn', 'withdrawn_at' => '2026-10-11T00:00:00Z'], ['id' => self::LISTING]);
        $client->request('GET', '/');
        self::assertSelectorNotExists('.offer-card');
    }

    public function testHomeDoesNotInventAnOfferWithoutAvailabilityOrWithUnquotablePrice(): void
    {
        $this->seed();
        $client = self::createClient();
        $client->request('GET', '/');
        self::assertSelectorNotExists('.offer-card');
        $this->database->update('resale_listings', ['price_cents' => PHP_INT_MAX], ['id' => self::LISTING]);
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.offer-card');
    }
}
