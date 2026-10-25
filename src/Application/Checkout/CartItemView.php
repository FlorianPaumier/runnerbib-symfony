<?php

declare(strict_types=1);

namespace App\Application\Checkout;

final readonly class CartItemView
{
    /** @param list<array{name:string,type:string,required:bool,options:list<string|int|bool>|null}> $registrationFields */
    public function __construct(public string $listingId, public string $raceId, public int $priceCents, public int $buyerFeeCents, public int $buyerTotalCents, public bool $canPurchase, public string $participation, public array $registrationFields = [])
    {
    }
}
