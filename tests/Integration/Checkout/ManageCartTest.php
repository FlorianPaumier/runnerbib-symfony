<?php

declare(strict_types=1);

namespace App\Tests\Integration\Checkout;

use App\Application\Checkout\ManageCart;
use App\Tests\Integration\Availability\AvailabilityTestCase;

final class ManageCartTest extends AvailabilityTestCase
{
    public function testUnverifiedBuyerCannotUseTheCart(): void
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test']);
        $this->database->update('accounts', ['verified_at' => null], ['id' => self::MEMBER]);
        $cart = new ManageCart(new \App\Infrastructure\Checkout\DbalCartStore($this->database), new \App\Application\Availability\FindOfferAccess(new \App\Infrastructure\Availability\DbalAvailabilityAccessStore($this->database)), new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Domain\Finance\FeePolicy());
        try {
            $cart->add(self::MEMBER, self::LISTING, new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('An unverified buyer used the cart.');
        } catch (\DomainException $error) {
            self::assertSame('An active verified buyer is required.', $error->getMessage());
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM cart_items'));
        }
    }

    public function testUnknownListingIsRefusedAndTheCartStaysEmpty(): void
    {
        $this->seed();
        $this->database->insert('account_private_profiles', ['account_id' => self::MEMBER, 'email' => 'buyer@example.test', 'email_normalized' => 'buyer@example.test']);
        $cart = new ManageCart(new \App\Infrastructure\Checkout\DbalCartStore($this->database), new \App\Application\Availability\FindOfferAccess(new \App\Infrastructure\Availability\DbalAvailabilityAccessStore($this->database)), new \App\Infrastructure\Operations\DbalTransactionManager($this->database), new \App\Domain\Finance\FeePolicy());
        try {
            $cart->add(self::MEMBER, '00000000-0000-0000-0000-000000000099', new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('An unknown listing was added to the cart.');
        } catch (\DomainException) {
            self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM cart_items'));
        }
    }
}
