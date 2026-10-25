<?php

declare(strict_types=1);

namespace App\Tests\Registration;

use App\Domain\Registration\RegistrationSchema;
use PHPUnit\Framework\TestCase;

final class RegistrationSchemaTest extends TestCase
{
    public function testExplicitSchemaValidatesRequiredFieldsTypesAndUnknownKeys(): void
    {
        $definition = json_decode('{"type":"object","properties":{"entry_code":{"type":"string"},"year":{"type":"integer"},"accepted":{"type":"boolean"}},"required":["entry_code"],"additionalProperties":false}', false, 512, JSON_THROW_ON_ERROR);
        $schema = new RegistrationSchema($definition);
        self::assertSame([], $schema->violations((object) ['entry_code' => '00123', 'year' => 2026, 'accepted' => false]));
        self::assertSame(['entry_code'], $schema->violations(new \stdClass()));
        self::assertSame(['year'], $schema->violations((object) ['entry_code' => '00123', 'year' => '2026']));
        self::assertSame(['accepted'], $schema->violations((object) ['entry_code' => '00123', 'accepted' => 'true']));
        self::assertSame(['extra'], $schema->violations((object) ['entry_code' => '00123', 'extra' => 'value']));
    }

    public function testNumericJsonPropertyNamesRemainTextualConfiguredKeys(): void
    {
        $definition = json_decode('{"type":"object","properties":{"0":{"type":"string"}},"required":["0"],"additionalProperties":false}', false, 512, JSON_THROW_ON_ERROR);
        $schema = new RegistrationSchema($definition);
        self::assertSame([], $schema->violations((object) ['0' => '00123']));
        self::assertSame(['0'], $schema->violations(new \stdClass()));
        self::assertSame(['0'], $schema->violations((object) ['0' => 123]));
    }

    public function testOnlyConfiguredBoundsAndEnumerationsRestrictValues(): void
    {
        $definition = json_decode('{"type":"object","properties":{"code":{"type":"string","minLength":2,"maxLength":4,"enum":["été","ab"]},"level":{"type":"integer","minimum":0,"maximum":3},"flag":{"type":"boolean","enum":[true]},"text":{"type":"string","minLength":2,"maxLength":4}},"required":[],"additionalProperties":false}', false, 512, JSON_THROW_ON_ERROR);
        $schema = new RegistrationSchema($definition);
        self::assertSame([], $schema->violations((object) ['code' => 'été', 'level' => 0, 'flag' => true]));
        self::assertSame([], $schema->violations((object) ['code' => 'ab', 'level' => 3]));
        self::assertSame(['code'], $schema->violations((object) ['code' => 'a']));
        self::assertSame(['code'], $schema->violations((object) ['code' => 'abcde']));
        self::assertSame(['code'], $schema->violations((object) ['code' => 'cd']));
        self::assertSame(['level'], $schema->violations((object) ['level' => -1]));
        self::assertSame(['level'], $schema->violations((object) ['level' => 4]));
        self::assertSame([], $schema->violations((object) ['text' => 'ab']));
        self::assertSame([], $schema->violations((object) ['text' => 'abcd']));
        self::assertSame(['text'], $schema->violations((object) ['text' => 'a']));
        self::assertSame(['text'], $schema->violations((object) ['text' => 'abcde']));
        self::assertSame(['flag'], $schema->violations((object) ['flag' => false]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDefinitions')]
    public function testUnsupportedOrMalformedSchemaFailsClosed(string $json): void
    {
        $definition = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $definition);
        $this->expectException(\InvalidArgumentException::class);
        new RegistrationSchema($definition);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidDefinitions(): iterable
    {
        foreach (['{}', '{"type":"array"}', '{"type":"object","properties":{},"required":[],"additionalProperties":true}', '{"type":"object","properties":{},"required":{},"additionalProperties":false}', '{"type":"object","properties":{},"required":["missing"],"additionalProperties":false}', '{"type":"object","properties":{},"required":[],"additionalProperties":false,"unknown":1}'] as $index => $json) {
            yield 'root '.$index => [$json];
        }
        foreach (['{"type":"number"}', '{"type":"string","pattern":"x"}', '{"type":"string","minLength":1.5}', '{"type":"boolean","minimum":0}', '{"type":"integer","maxLength":4}', '{"type":"integer","minimum":3,"maximum":2}', '{"type":"string","minLength":-1}', '{"type":"string","maxLength":-1}', '{"type":"string","minLength":3,"maxLength":2}', '{"type":"string","enum":[]}', '{"type":"string","enum":{}}', '{"type":"integer","enum":[1,1]}', '{"type":"integer","enum":["1"]}', '{"type":"string","enum":[{}]}', 'null'] as $index => $field) {
            yield 'field '.$index => ['{"type":"object","properties":{"configured":'.$field.'},"required":[],"additionalProperties":false}'];
        }
        yield 'empty field name' => ['{"type":"object","properties":{"":{"type":"string"}},"required":[],"additionalProperties":false}'];
        yield 'duplicate required' => ['{"type":"object","properties":{"configured":{"type":"string"}},"required":["configured","configured"],"additionalProperties":false}'];
        yield 'typed required' => ['{"type":"object","properties":{},"required":[1],"additionalProperties":false}'];
    }
}
