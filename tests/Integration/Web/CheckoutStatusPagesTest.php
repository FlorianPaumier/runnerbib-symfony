<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Infrastructure\Identity\IdentityUserProvider;
use App\Tests\Integration\Availability\AvailabilityTestCase;

final class CheckoutStatusPagesTest extends AvailabilityTestCase
{
    private const CHECKOUT = '00000000-0000-0000-0000-000000000080';

    public function testReturnFlagsCannotConfirmPaymentAndOtherBuyersCannotReadStatus(): void
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test']);
        $this->database->insert('checkouts', ['id' => self::CHECKOUT, 'buyer_id' => self::MEMBER, 'state' => 'pending', 'authorized_at' => '2026-10-10T11:00:00Z', 'initial_deadline' => '2026-10-10T11:15:00Z', 'expected_total_cents' => 10500, 'currency' => 'EUR']);
        $client = self::createClient();
        $client->request('GET', '/achat/'.self::CHECKOUT.'/retour?success=1');
        self::assertResponseRedirects('/connexion');
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('buyer@example.test'));
        $client->request('GET', '/achat/'.self::CHECKOUT.'/retour?success=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'confirmation');
        self::assertSelectorTextContains('main', '105,00');
        self::assertSelectorExists('a[href="/achat/'.self::CHECKOUT.'/retour"]');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('pending', $this->database->fetchOne('SELECT state FROM checkouts WHERE id=?', [self::CHECKOUT]));
        foreach (['processing' => 'protégés', 'unknown' => 'reste à vérifier', 'cancelled' => 'Achat annulé', 'failed' => 'Achat non abouti', 'completed' => 'Achat confirmé par la plateforme'] as $state => $message) {
            $this->database->update('checkouts', ['state' => $state, 'completed_at' => 'completed' === $state ? '2026-10-10T11:03:00Z' : null], ['id' => self::CHECKOUT]);
            $client->request('GET', '/achat/'.self::CHECKOUT.'/retour?success=1');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', $message);
            self::assertSelectorNotExists('form');
        }
        $client->request('GET', '/achat/00000000-0000-0000-0000-000000000081/retour');
        self::assertResponseStatusCodeSame(404);
        $this->database->insert('account_private_profiles', ['account_id' => self::LATE, 'email' => 'other@example.test', 'email_normalized' => 'other@example.test']);
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('other@example.test'));
        $client->request('GET', '/achat/'.self::CHECKOUT.'/retour');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextNotContains('body', '105,00');
    }

    public function testAnonymousUseCaseAndStringifiedDatabaseCannotExposeUntrustedStatus(): void
    {
        $this->seed();
        $this->database->insert('checkouts', ['id' => self::CHECKOUT, 'buyer_id' => self::MEMBER, 'state' => 'pending', 'authorized_at' => '2026-10-10T11:00:00Z', 'initial_deadline' => '2026-10-10T11:15:00Z', 'expected_total_cents' => 10500, 'currency' => 'EUR']);
        $identity = new class implements \App\Application\Identity\CurrentIdentity {
            public function accountId(): ?string
            {
                return null;
            }
        };
        $store = new \App\Infrastructure\Checkout\DbalCheckoutStatusStore($this->database);
        self::assertNull((new \App\Application\Checkout\FindCheckoutStatus($identity, $store))->find(self::CHECKOUT));
        $anonymous = new class implements \App\Application\Identity\CurrentIdentity {
            public function accountId(): ?string
            {
                return null;
            }
        };
        self::assertNull((new \App\Application\Checkout\FindCheckoutStatus($anonymous, $store))->findBySession('cs_hosted'));
        self::assertNull((new \App\Application\Checkout\FindCheckoutStatus($identity, $store))->findBySession(''));
        $connection = $this->stringifiedDatabase();
        try {
            $this->expectException(\UnexpectedValueException::class);
            (new \App\Infrastructure\Checkout\DbalCheckoutStatusStore($connection))->forBuyer(self::CHECKOUT, self::MEMBER);
        } finally {
            $connection->close();
        }
    }
}
