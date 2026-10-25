<?php

declare(strict_types=1);

namespace App\Application\Checkout;

interface CheckoutAuthorizationStore
{
    /**
     * Sellers of the buyer's cart whose backend-confirmed transfer readiness is missing.
     *
     * @return list<string>
     */
    public function unreadySellers(string $buyerId, string $scope): array;

    /** @return list<CheckoutOffer> */
    public function lockCart(string $buyerId, string $scope): array;

    /** @return array{display_name:string,email:string} */
    public function identity(string $buyerId): array;

    public function id(): string;

    /** @param list<array<string,mixed>> $items */
    public function commit(CheckoutAuthorizationView $checkout, string $buyerId, \DateTimeImmutable $now, array $items, string $parameters, string $scope): void;
}
