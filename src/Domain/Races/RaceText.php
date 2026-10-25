<?php

declare(strict_types=1);

namespace App\Domain\Races;

final class RaceText
{
    public static function required(string $value, int $limit): string
    {
        $value = trim($value);
        $length = preg_match_all('/./us', $value);
        if (false === $length || 0 === $length || $length > $limit) {
            throw new \InvalidArgumentException('Invalid race text field.');
        }

        return $value;
    }

    public static function optional(?string $value, ?int $limit = null): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return null === $limit ? trim($value) : self::required($value, $limit);
    }
}
