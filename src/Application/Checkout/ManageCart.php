<?php

declare(strict_types=1);

namespace App\Application\Checkout;

use App\Application\Availability\FindOfferAccess;
use App\Application\Operations\TransactionManager;
use App\Domain\Checkout\CartQuote;
use App\Domain\Finance\FeePolicy;
use App\Domain\Registration\RegistrationSchema;

final readonly class ManageCart
{
    public function __construct(private CartStore $store, private FindOfferAccess $access, private TransactionManager $transactions, private FeePolicy $fees)
    {
    }

    public function add(string $buyerId, string $listingId, \DateTimeImmutable $now): void
    {
        $this->transactions->run(function () use ($buyerId, $listingId, $now): void {
            $this->store->coordinateBuyer($buyerId);
            $access = $this->access->forListing($listingId, $buyerId, $now);
            if (null === $access || !$access->canInitiatePurchase) {
                throw new \DomainException('Listing is unavailable for this buyer.');
            }
            $quote = new CartQuote($buyerId, $this->fees);
            foreach ($this->store->items($buyerId) as $item) {
                $quote->add($item->raceId, $item->sellerId, $item->priceCents);
            }
            $offer = $this->store->offer($listingId);
            $quote->add($offer->raceId, $offer->sellerId, $offer->priceCents);
            $this->store->add($buyerId, $listingId, $now);
        });
    }

    public function remove(string $buyerId, string $listingId): void
    {
        $this->store->remove($buyerId, $listingId);
    }

    public function view(string $buyerId, \DateTimeImmutable $now): CartView
    {
        $quote = new CartQuote($buyerId, $this->fees);
        $items = [];
        $offers = $this->store->items($buyerId);
        $access = $this->access->forListings(array_values(array_unique(array_column($offers, 'listingId'))), $buyerId, $now);
        foreach ($offers as $offer) {
            $quote->add($offer->raceId, $offer->sellerId, $offer->priceCents);
            $fee = $this->fees->quote($offer->priceCents);
            $offerAccess = $access[$offer->listingId] ?? null;
            $items[] = new CartItemView($offer->listingId, $offer->raceId, $fee->priceCents, $fee->buyerFeeCents, $fee->buyerTotalCents, null !== $offerAccess && $offerAccess->canInitiatePurchase, $offer->participation, $this->registrationFields($offer->registrationSchema));
        }

        $payable = [] !== $items;
        foreach ($items as $item) {
            $payable = $payable && $item->canPurchase;
        }

        return new CartView($items, $quote->priceCents(), $quote->buyerFeeCents(), $quote->buyerTotalCents(), $payable);
    }

    /**
     * @return list<array{name:string,type:string,required:bool,options:list<string|int|bool>|null}>
     */
    private function registrationFields(?\stdClass $schema): array
    {
        if (null === $schema) {
            return [];
        }
        $definition = new RegistrationSchema($schema);
        $fields = [];
        foreach ($definition->fields() as $name => $field) {
            $fields[] = ['name' => $name, 'type' => $field->type()->value, 'required' => in_array($name, $definition->required(), true), 'options' => $field->options()];
        }

        return $fields;
    }
}
