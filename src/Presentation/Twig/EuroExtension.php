<?php

declare(strict_types=1);

namespace App\Presentation\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class EuroExtension extends AbstractExtension
{
    /** @return list<TwigFilter> */
    public function getFilters(): array
    {
        return [new TwigFilter('euros', $this->euros(...))];
    }

    public function euros(int $cents): string
    {
        $whole = strrev(implode(' ', str_split(strrev((string) abs(intdiv($cents, 100))), 3)));

        return ($cents < 0 ? '-' : '').$whole.','.str_pad((string) abs($cents % 100), 2, '0', STR_PAD_LEFT).' €';
    }
}
