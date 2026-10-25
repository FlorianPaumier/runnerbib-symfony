<?php

declare(strict_types=1);

namespace App\Application\Notifications;

final readonly class AvailabilityDigest
{
    /** @param list<array{notification_id:string,race_id:string,listing_id:string,price_cents:int,deadline:\DateTimeImmutable}> $offers */
    public function __construct(public string $email, public array $offers)
    {
    }
}
