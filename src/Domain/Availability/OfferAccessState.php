<?php

declare(strict_types=1);

namespace App\Domain\Availability;

enum OfferAccessState: string
{
    case Unavailable = 'unavailable';
    case Priority = 'priority';
    case Public = 'public';
}
