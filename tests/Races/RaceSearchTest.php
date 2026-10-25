<?php

declare(strict_types=1);

namespace App\Tests\Races;

use App\Application\Races\RaceCursor;
use App\Application\Races\RaceSearch;
use PHPUnit\Framework\TestCase;

/** @phpstan-type SearchArguments array{limit?:int,startsFrom?:\DateTimeImmutable,startsUntil?:\DateTimeImmutable,minDistanceMetres?:int,maxDistanceMetres?:int} */
final class RaceSearchTest extends TestCase
{
    /** @param SearchArguments $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSearches')]
    public function testInvalidSearchIsRejected(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RaceSearch(...$arguments);
    }

    /** @return iterable<string, array{SearchArguments}> */
    public static function invalidSearches(): iterable
    {
        yield 'zero page' => [['limit' => 0]];
        yield 'oversized page' => [['limit' => 101]];
        yield 'inverted dates' => [['startsFrom' => new \DateTimeImmutable('2028-01-01Z'), 'startsUntil' => new \DateTimeImmutable('2027-01-01Z')]];
        yield 'negative distance' => [['minDistanceMetres' => -1]];
        yield 'inverted distance' => [['minDistanceMetres' => 200, 'maxDistanceMetres' => 100]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCursors')]
    public function testMalformedCursorHasActionableFailure(string $token): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RaceCursor::fromToken($token);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCursors(): iterable
    {
        yield 'encoding' => ['not-base64!'];
        yield 'trailing separator' => [base64_encode('2027-04-01T08:00:00.000000+00:00|00000000-0000-0000-0000-000000000001|extra')];
        yield 'structure' => [base64_encode('no-separator')];
        yield 'instant' => [base64_encode('invalid|00000000-0000-0000-0000-000000000001')];
        yield 'date rollover' => [base64_encode('2027-02-31T08:00:00.000000+00:00|00000000-0000-0000-0000-000000000001')];
        yield 'oversize' => [base64_encode(str_repeat('a', 101))];
        yield 'identity' => [base64_encode('2027-04-01T08:00:00.000000+00:00|invalid')];
    }

    public function testSearchIncludesBothPageBoundsAndDistanceBoundaries(): void
    {
        self::assertSame(1, (new RaceSearch(limit: 1, minDistanceMetres: 1, maxDistanceMetres: 1))->limit);
        self::assertSame(100, (new RaceSearch(limit: 100))->limit);
    }

    public function testAnExactInstantAndOneSidedDateRangeAreAllowed(): void
    {
        $instant = new \DateTimeImmutable('2027-04-01T10:00:00Z');
        self::assertSame($instant, (new RaceSearch(startsFrom: $instant, startsUntil: $instant))->startsUntil);
        self::assertNull((new RaceSearch(startsFrom: $instant))->startsUntil);
        self::assertNull((new RaceSearch(startsUntil: $instant))->startsFrom);
    }
}
