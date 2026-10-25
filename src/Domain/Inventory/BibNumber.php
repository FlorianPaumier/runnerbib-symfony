<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

final readonly class BibNumber
{
    public string $value;

    public function __construct(string $number)
    {
        $this->value = trim($number);
        if ('' === $this->value) {
            throw new \InvalidArgumentException('A bib number is required.');
        }
    }
}
