<?php

declare(strict_types=1);

namespace App\Tests\Races;

use App\Domain\Races\Distance;
use App\Domain\Races\Race;
use App\Domain\Races\RaceId;
use App\Domain\Races\RaceLocation;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type RaceChanges array{name?:string,timezone?:string,sportCode?:string,version?:int,updatedAt?:\DateTimeImmutable,publicationState?:string,participation?:string,organizationId?:RaceId,minPriceCents?:int,maxPriceCents?:int,officialUrl?:string,imageUrl?:string,registrationSchemaVersion?:int,registrationSchema?:\stdClass}
 */
final class RaceTest extends TestCase
{
    public function testDistancePreservesExactMetres(): void
    {
        self::assertSame(42195, (new Distance(42195))->metres);
    }

    public function testRacePreservesPublicIdentityAndLocation(): void
    {
        $race = new Race(
            new RaceId('00000000-0000-0000-0000-000000000001'),
            'Paris Marathon', new \DateTimeImmutable('2027-04-01T10:00:00+02:00'),
            'Europe/Paris', 'running', new Distance(42195), new RaceLocation('Paris'),
            new \DateTimeImmutable('2026-10-08Z'), new \DateTimeImmutable('2026-10-08Z'),
        );
        self::assertSame('00000000-0000-0000-0000-000000000001', $race->id->value);
        self::assertSame('Paris Marathon', $race->name);
        self::assertSame('2027-04-01T08:00:00+00:00', $race->startsAt->format(DATE_ATOM));
        self::assertSame('FR', $race->location->countryCode);
        self::assertSame('Paris', $race->location->city);
        self::assertSame(1, $race->version);
        self::assertSame('draft', $race->publicationState);
        self::assertSame('nonparticipating', $race->participation);
    }

    public function testMaximumNameLengthAndDistanceAreAccepted(): void
    {
        $race = new Race(new RaceId('00000000-0000-0000-0000-000000000001'), str_repeat('a', 255), new \DateTimeImmutable('2027-04-01Z'), 'UTC', 'running', new Distance(2147483647), new RaceLocation('Paris', latitude: '90.000000', longitude: '-180.000000'), new \DateTimeImmutable('2026-10-08Z'), new \DateTimeImmutable('2026-10-08Z'));
        self::assertSame(255, strlen($race->name));
        self::assertSame(2147483647, $race->distance->metres);
        self::assertSame('90.000000', $race->location->latitude);
        self::assertSame('-180.000000', $race->location->longitude);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIdentities')]
    public function testInvalidIdentityIsRejected(string $identity): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RaceId($identity);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdentities(): iterable
    {
        yield 'malformed' => ['not-a-uuid'];
        yield 'prefix' => ['x00000000-0000-0000-0000-000000000001'];
        yield 'suffix' => ['00000000-0000-0000-0000-000000000001x'];
        yield 'newline' => ["00000000-0000-0000-0000-000000000001\n"];
    }

    public function testInvalidLocationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RaceLocation(' ');
    }

    /** @param array{city:string,countryCode?:string,latitude?:?string,longitude?:?string,street?:string,region?:string,postalCode?:string} $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLocations')]
    public function testLocationRejectsUnsupportedMarketAndInvalidCoordinates(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RaceLocation(...$arguments);
    }

    /** @return iterable<string, array{array{city:string,countryCode?:string,latitude?:?string,longitude?:?string,street?:string,region?:string,postalCode?:string}}> */
    public static function invalidLocations(): iterable
    {
        yield 'prefix coordinate' => [['city' => 'Paris', 'latitude' => 'x1', 'longitude' => '1']];
        yield 'suffix coordinate' => [['city' => 'Paris', 'latitude' => '1x', 'longitude' => '1']];
        yield 'newline coordinate' => [['city' => 'Paris', 'latitude' => "1\n", 'longitude' => '1']];
        yield 'city too long' => [['city' => str_repeat('x', 256)]];
        yield 'street too long' => [['city' => 'Paris', 'street' => str_repeat('x', 256)]];
        yield 'region too long' => [['city' => 'Paris', 'region' => str_repeat('x', 256)]];
        yield 'postal code too long' => [['city' => 'Paris', 'postalCode' => str_repeat('0', 17)]];
        yield 'market' => [['city' => 'Paris', 'countryCode' => 'US']];
        yield 'missing longitude' => [['city' => 'Paris', 'latitude' => '48.000000']];
        yield 'invalid coordinate' => [['city' => 'Paris', 'latitude' => 'x', 'longitude' => '1']];
        yield 'latitude range' => [['city' => 'Paris', 'latitude' => '90.000001', 'longitude' => '1']];
        yield 'longitude range' => [['city' => 'Paris', 'latitude' => '1', 'longitude' => '-180.000001']];
    }

    /** @param RaceChanges $changes */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRaceMetadata')]
    public function testRaceRejectsInvalidMetadata(array $changes): void
    {
        $arguments = [
            'id' => new RaceId('00000000-0000-0000-0000-000000000001'),
            'name' => 'Paris', 'startsAt' => new \DateTimeImmutable('2027-04-01Z'),
            'timezone' => 'Europe/Paris', 'sportCode' => 'running', 'distance' => new Distance(1),
            'location' => new RaceLocation('Paris'), 'createdAt' => new \DateTimeImmutable('2026-10-08Z'),
            'updatedAt' => new \DateTimeImmutable('2026-10-08Z'),
        ];
        $this->expectException(\InvalidArgumentException::class);
        new Race(...array_replace($arguments, $changes));
    }

    /** @return iterable<string, array{RaceChanges}> */
    public static function invalidRaceMetadata(): iterable
    {
        yield 'name too long' => [['name' => str_repeat('x', 256)]];
        yield 'blank name' => [['name' => ' ']];
        yield 'timezone' => [['timezone' => 'Mars/Olympus']];
        yield 'sport newline' => [['sportCode' => "running\n"]];
        yield 'invalid image' => [['imageUrl' => 'javascript:alert(1)']];
        yield 'invalid http URL' => [['officialUrl' => 'http://']];
        yield 'unsupported URL scheme' => [['officialUrl' => 'ftp://example.test']];
        yield 'sport code' => [['sportCode' => 'invalid sport']];
        yield 'version' => [['version' => 0]];
        yield 'timestamp order' => [['updatedAt' => new \DateTimeImmutable('2026-01-01Z')]];
        yield 'publication' => [['publicationState' => 'hidden']];
        yield 'participation' => [['participation' => 'unknown']];
        yield 'participating without organizer' => [['participation' => 'participating']];
        yield 'nonparticipating bounds' => [['minPriceCents' => 100]];
        yield 'invalid public URL' => [['officialUrl' => 'javascript:alert(1)']];
        yield 'positive registration version' => [['registrationSchemaVersion' => 0, 'registrationSchema' => new \stdClass()]];
        yield 'positive min bound' => [['participation' => 'participating', 'organizationId' => new RaceId('00000000-0000-0000-0000-000000000002'), 'minPriceCents' => 0]];
        yield 'bound ordering' => [['participation' => 'participating', 'organizationId' => new RaceId('00000000-0000-0000-0000-000000000002'), 'minPriceCents' => 200, 'maxPriceCents' => 100]];
        yield 'registration pair' => [['registrationSchemaVersion' => 1]];
    }

    public function testEqualAndOptionalParticipatingBoundsAreAllowed(): void
    {
        foreach ([[100, 100], [100, null], [null, 100]] as [$min, $max]) {
            $race = new Race(new RaceId('00000000-0000-0000-0000-000000000001'), 'Race', new \DateTimeImmutable('2027-04-01Z'), 'UTC', 'running', new Distance(1), new RaceLocation(str_repeat('a', 255)), new \DateTimeImmutable('2026-10-08Z'), new \DateTimeImmutable('2026-10-08Z'), organizationId: new RaceId('00000000-0000-0000-0000-000000000002'), participation: 'participating', minPriceCents: $min, maxPriceCents: $max);
            self::assertSame($min, $race->minPriceCents);
            self::assertSame($max, $race->maxPriceCents);
            self::assertSame(255, strlen($race->location->city));
        }
    }

    public function testLocationNormalizesOptionalBlankFields(): void
    {
        $location = new RaceLocation(' Paris ', street: ' ', region: ' ', postalCode: ' ');
        self::assertSame('Paris', $location->city);
        self::assertNull($location->street);
        self::assertNull($location->region);
        self::assertNull($location->postalCode);
    }

    public function testRaceNormalizesTitleAndBlankOptionalMetadata(): void
    {
        $race = new Race(new RaceId('00000000-0000-0000-0000-000000000001'), ' Paris ', new \DateTimeImmutable('2027-04-01Z'), 'UTC', 'running', new Distance(1), new RaceLocation('Paris'), new \DateTimeImmutable('2026-10-08Z'), new \DateTimeImmutable('2026-10-08Z'), description: ' ', imageUrl: ' ', officialUrl: ' ');
        self::assertSame('Paris', $race->name);
        self::assertNull($race->description);
        self::assertNull($race->imageUrl);
        self::assertNull($race->officialUrl);
    }

    public function testTextLimitsCountFrenchCharactersInsteadOfUtf8Bytes(): void
    {
        $title = str_repeat('é', 255);
        $race = new Race(new RaceId('00000000-0000-0000-0000-000000000001'), $title, new \DateTimeImmutable('2027-04-01Z'), 'UTC', 'running', new Distance(1), new RaceLocation($title), new \DateTimeImmutable('2026-10-08Z'), new \DateTimeImmutable('2026-10-08Z'));
        self::assertSame($title, $race->name);
        self::assertSame($title, $race->location->city);
    }

    public function testNonPositiveDistanceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Distance(0);
    }

    public function testOptionalLocationFieldsAcceptTheirExactLimits(): void
    {
        $location = new RaceLocation('Paris', street: str_repeat('é', 255), region: str_repeat('é', 255), postalCode: str_repeat('0', 16));
        self::assertSame(str_repeat('é', 255), $location->street);
        self::assertSame(str_repeat('é', 255), $location->region);
        self::assertSame('0000000000000000', $location->postalCode);
    }

    public function testFreeTextIsTrimmedWithoutInventingALengthLimit(): void
    {
        self::assertSame('Une description', \App\Domain\Races\RaceText::optional(' Une description '));
    }
}
