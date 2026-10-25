<?php

declare(strict_types=1);

namespace App\Tests\Registration;

use App\Domain\Registration\RegistrationSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistrationBoundsTest extends TestCase
{
    /** @param list<string|int> $accepted */
    #[DataProvider('bounds')]
    public function testOnlyTheConfiguredBoundRestrictsAnswers(string $field, array $accepted, string|int $rejected): void
    {
        $definition = json_decode('{"type":"object","properties":{"answer":'.$field.'},"required":["answer"],"additionalProperties":false}', false, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $definition);
        $schema = new RegistrationSchema($definition);
        foreach ($accepted as $answer) {
            self::assertSame([], $schema->violations((object) ['answer' => $answer]));
        }
        self::assertSame(['answer'], $schema->violations((object) ['answer' => $rejected]));
    }

    /** @return iterable<string,array{string,list<string|int>,string|int}> */
    public static function bounds(): iterable
    {
        yield 'minimum only' => ['{"type":"integer","minimum":3}', [3, 1000], 2];
        yield 'maximum only' => ['{"type":"integer","maximum":-3}', [-3, -1000], -2];
        yield 'minimum length only' => ['{"type":"string","minLength":2}', ['éé', 'une longue réponse'], 'é'];
        yield 'maximum length only' => ['{"type":"string","maxLength":2}', ['', 'éé'], 'ééé'];
        yield 'exact integer' => ['{"type":"integer","minimum":3,"maximum":3}', [3], 2];
        yield 'exact integer upper edge' => ['{"type":"integer","minimum":3,"maximum":3}', [3], 4];
        yield 'empty text allowed' => ['{"type":"string","minLength":0,"maxLength":0}', [''], 'a'];
    }

    public function testAnUnboundedIntegerCanBeNegative(): void
    {
        $definition = json_decode('{"type":"object","properties":{"answer":{"type":"integer"}},"required":[],"additionalProperties":false}', false, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], (new RegistrationSchema($definition))->violations((object) ['answer' => -1000]));
    }
}
