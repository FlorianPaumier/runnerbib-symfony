<?php

declare(strict_types=1);

namespace App\Tests\Registration;

use App\Application\Races\RaceDefinition;
use PHPUnit\Framework\TestCase;

final class RaceRegistrationDefinitionTest extends TestCase
{
    public function testRaceDefinitionRejectsUnsupportedRegistrationConfiguration(): void
    {
        $definition = new RaceDefinition(id: '00000000-0000-0000-0000-000000000001', name: 'Synthetic race', startsAt: new \DateTimeImmutable('2027-04-01Z'), timezone: 'Europe/Paris', sportCode: 'running', distanceMetres: 10000, city: 'Paris', registrationSchemaVersion: 1, registrationSchema: (object) ['fields' => []]);
        $this->expectException(\InvalidArgumentException::class);
        $definition->toRace(new \DateTimeImmutable('2026-10-10Z'), new \DateTimeImmutable('2026-10-10Z'));
    }
}
