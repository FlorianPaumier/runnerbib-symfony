<?php

declare(strict_types=1);

namespace App\Tests\Integration\Operations;

use App\Application\Operations\OutboxEventHandler;
use App\Application\Operations\OutboxMessage;
use App\Infrastructure\Operations\OutboxMessageRouter;
use PHPUnit\Framework\TestCase;

final class OutboxRouterTest extends TestCase
{
    public function testExactlyOneSupportingHandlerReceivesTheEvent(): void
    {
        $first = $this->handler('identity.credential_mail');
        $other = $this->handler('availability.published');
        (new OutboxMessageRouter([$first, $other]))($this->message());
        self::assertSame(1, $first->calls);
        self::assertSame(0, $other->calls);
    }

    public function testUnknownEventFailsWithoutCallingAnyHandler(): void
    {
        $handler = $this->handler('availability.published');
        try {
            (new OutboxMessageRouter([$handler]))($this->message());
            self::fail('An unsupported event must fail');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Unsupported or ambiguous outbox event type', $exception->getMessage());
        }
        self::assertSame(0, $handler->calls);
    }

    public function testAmbiguousRegistryFailsBeforeEitherHandlerRuns(): void
    {
        $first = $this->handler('identity.credential_mail');
        $second = $this->handler('identity.credential_mail');
        try {
            (new OutboxMessageRouter([$first, $second]))($this->message());
            self::fail('Ambiguous routing must fail');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(0, $first->calls);
        self::assertSame(0, $second->calls);
    }

    private function handler(string $eventType): RecordingOutboxHandler
    {
        return new RecordingOutboxHandler($eventType);
    }

    private function message(): OutboxMessage
    {
        return new OutboxMessage('00000000-0000-0000-0000-000000000001', 'mail:1', 'identity_mail', '00000000-0000-0000-0000-000000000001', 1, 'identity.credential_mail', 1, '{}', new \DateTimeImmutable('2026-10-08Z'));
    }
}

final class RecordingOutboxHandler implements OutboxEventHandler
{
    public int $calls = 0;

    public function __construct(private string $eventType)
    {
    }

    public function supports(string $eventType): bool
    {
        return $eventType === $this->eventType;
    }

    public function __invoke(OutboxMessage $message): void
    {
        ++$this->calls;
    }
}
