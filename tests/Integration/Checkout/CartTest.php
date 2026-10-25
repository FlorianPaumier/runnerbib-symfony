<?php

declare(strict_types=1);

namespace App\Tests\Integration\Checkout;

use App\Application\Availability\FindOfferAccess;
use App\Application\Checkout\ManageCart;
use App\Domain\Finance\FeePolicy;
use App\Infrastructure\Availability\DbalAvailabilityAccessStore;
use App\Infrastructure\Checkout\DbalCartStore;
use App\Infrastructure\Operations\DbalTransactionManager;
use App\Tests\Integration\Availability\AvailabilityTestCase;

final class CartTest extends AvailabilityTestCase
{
    public function testAddingAnOfferPersistsOnlyBuyerCartAndNeverReservesIt(): void
    {
        $this->seed();
        $this->database->beginTransaction();
        $this->publisher()->publish(self::EVENT, self::LISTING, new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $this->database->commit();
        $cart = new ManageCart(new DbalCartStore($this->database), new FindOfferAccess(new DbalAvailabilityAccessStore($this->database)), new DbalTransactionManager($this->database), new FeePolicy());
        $cart->add(self::MEMBER, self::LISTING, new \DateTimeImmutable('2026-10-10T11:00:00Z'));
        self::assertSame(self::LISTING, $this->database->fetchOne('SELECT listing_id::text FROM cart_items WHERE buyer_id = ?', [self::MEMBER]));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM checkout_items'));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM buyer_race_guards'));
        self::assertSame(10500, $cart->view(self::MEMBER, new \DateTimeImmutable('2026-10-10T11:00:00Z'))->buyerTotalCents);
        try {
            $cart->add(self::MEMBER, self::LISTING, new \DateTimeImmutable('2026-10-10T11:00:01Z'));
            self::fail('Duplicate race item accepted.');
        } catch (\DomainException) {
            self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM cart_items'));
        }
        $this->database->update('resale_listings', ['state' => 'withdrawn', 'withdrawn_at' => '2026-10-10T11:01:00Z'], ['id' => self::LISTING]);
        $view = $cart->view(self::MEMBER, new \DateTimeImmutable('2026-10-10T11:02:00Z'));
        self::assertCount(1, $view->items);
        self::assertFalse($view->items[0]->canPurchase);
        $cart->remove(self::LATE, self::LISTING);
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM cart_items'));
        $cart->remove(self::MEMBER, self::LISTING);
        self::assertCount(0, $cart->view(self::MEMBER, new \DateTimeImmutable('2026-10-10T11:02:00Z'))->items);
    }

    public function testMissingOfferCannotCreateAnEntry(): void
    {
        $this->seed();
        $cart = new ManageCart(new DbalCartStore($this->database), new FindOfferAccess(new DbalAvailabilityAccessStore($this->database)), new DbalTransactionManager($this->database), new FeePolicy());
        try {
            $cart->add(self::MEMBER, '00000000-0000-0000-0000-000000000099', new \DateTimeImmutable('2026-10-10Z'));
            self::fail('Missing offer accepted.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM cart_items'));
        }
    }

    public function testSuspendedBuyerCannotCreateAnEntry(): void
    {
        $this->seed();
        $this->database->update('accounts', ['lifecycle_state' => 'suspended'], ['id' => self::MEMBER]);
        $cart = new ManageCart(new DbalCartStore($this->database), new FindOfferAccess(new DbalAvailabilityAccessStore($this->database)), new DbalTransactionManager($this->database), new FeePolicy());
        $this->expectException(\DomainException::class);
        $cart->add(self::MEMBER, self::LISTING, new \DateTimeImmutable('2026-10-10Z'));
    }
}
