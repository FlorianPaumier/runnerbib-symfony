<?php

declare(strict_types=1);

namespace App\Application\Races;

use App\Domain\Races\RaceId;

final readonly class RaceCursor
{
    public function __construct(public \DateTimeImmutable $startsAt, public RaceId $id)
    {
    }

    public function toToken(): string
    {
        return base64_encode($this->startsAt->format('Y-m-d\TH:i:s.uP').'|'.$this->id->value);
    }

    public static function fromToken(string $token): self
    {
        $decoded = base64_decode($token, true);
        if (false === $decoded || strlen($decoded) > 100) {
            throw new \InvalidArgumentException('Invalid race cursor.');
        }
        $parts = explode('|', $decoded);

        if (2 !== count($parts)) {
            throw new \InvalidArgumentException('Invalid race cursor.');
        }
        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $parts[0]);
        if (false === $instant || $instant->format('Y-m-d\TH:i:s.uP') !== $parts[0]) {
            throw new \InvalidArgumentException('Invalid cursor instant.');
        }

        return new self($instant, new RaceId($parts[1]));
    }
}
