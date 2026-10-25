<?php

declare(strict_types=1);

namespace App\Infrastructure\Checkout;

use App\Application\Checkout\CheckoutStatus;
use App\Application\Checkout\CheckoutStatusStore;
use Doctrine\DBAL\Connection;

final readonly class DbalCheckoutStatusStore implements CheckoutStatusStore
{
    public function __construct(private Connection $database)
    {
    }

    public function forBuyer(string $checkoutId, string $buyerId): ?CheckoutStatus
    {
        $row = $this->database->fetchAssociative('SELECT id::text,state,expected_total_cents FROM checkouts WHERE id=? AND buyer_id=?', [$checkoutId, $buyerId]);

        return false === $row ? null : $this->status($row);
    }

    public function forBuyerBySession(string $sessionReference, string $buyerId): ?CheckoutStatus
    {
        $row = $this->database->fetchAssociative('SELECT c.id::text,c.state,c.expected_total_cents FROM checkouts c JOIN payment_attempts p ON p.checkout_id=c.id WHERE p.session_ref=? AND c.buyer_id=?', [$sessionReference, $buyerId]);

        return false === $row ? null : $this->status($row);
    }

    /** @param array<string,mixed> $row */
    private function status(array $row): CheckoutStatus
    {
        if (!is_string($row['id']) || !is_string($row['state']) || !is_int($row['expected_total_cents'])) {
            throw new \UnexpectedValueException('Invalid stored checkout status.');
        }

        return new CheckoutStatus($row['id'], $row['state'], $row['expected_total_cents']);
    }
}
