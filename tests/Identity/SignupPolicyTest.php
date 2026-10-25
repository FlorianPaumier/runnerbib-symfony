<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Domain\Identity\Signup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignupPolicyTest extends TestCase
{
    public function testSignupNormalizesEmailWithoutRequiringName(): void
    {
        $signup = new Signup(' Runner@Example.test ', 'correct horse battery staple', true);
        self::assertSame('runner@example.test', $signup->email);
        self::assertSame('correct horse battery staple', $signup->password);
    }

    /** @return iterable<string, array{string,string,bool}> */
    public static function invalid(): iterable
    {
        yield 'email' => ['bad', 'correct horse battery staple', true];
        yield 'one below minimum' => ['runner@example.test', str_repeat('x', 11), true];
        yield 'password' => ['runner@example.test', 'short', true];
        yield 'too long' => ['runner@example.test', str_repeat('x', 4097), true];
        yield 'terms' => ['runner@example.test', 'correct horse battery staple', false];
    }

    #[DataProvider('invalid')]
    public function testInvalidSignupIsRejected(string $email, string $password, bool $terms): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Signup($email, $password, $terms);
    }

    public function testPasswordLengthBoundariesAreExact(): void
    {
        self::assertSame('xxxxxxxxxxxx', (new Signup('runner@example.test', str_repeat('x', 12), true))->password);
        self::assertSame(4096, strlen((new Signup('runner@example.test', str_repeat('x', 4096), true))->password));
    }
}
