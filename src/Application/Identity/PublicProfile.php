<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class PublicProfile
{
    /** @param list<array{race_id:string,race_name:string,bib_number:string}> $bibs */
    public function __construct(public ?string $displayName, public array $bibs)
    {
    }
}
