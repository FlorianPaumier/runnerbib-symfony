<?php

declare(strict_types=1);

namespace App\Tests\Integration\Newsletter;

use App\Application\Newsletter\NewsletterSubscription;
use App\Infrastructure\Newsletter\SymfonyNewsletterMailSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class NewsletterMailTest extends TestCase
{
    public function testControlledSmtpReceivesFrenchWelcomeAndThePurposeSpecificOneClickLink(): void
    {
        $transport = new class implements TransportInterface {
            /** @var list<RawMessage> */
            public array $messages = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->messages[] = $message;

                return null;
            }

            public function __toString(): string
            {
                return 'test://newsletter';
            }
        };
        $sender = new SymfonyNewsletterMailSender($transport, 'https://runner.example.test/', 'newsletter@example.test');
        $sender->send(new NewsletterSubscription('00000000-0000-0000-0000-000000000001', 'runner@example.test', new \DateTimeImmutable('2026-10-10Z')), 'purpose-signed-token', 'stable-id@runnerbib.local');
        self::assertCount(1, $transport->messages);
        $mail = $transport->messages[0];
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('Bienvenue à la lettre RelaisDossard', $mail->getSubject());
        self::assertSame('runner@example.test', $mail->getTo()[0]->getAddress());
        self::assertSame('newsletter@example.test', $mail->getFrom()[0]->getAddress());
        $body = $mail->getTextBody();
        self::assertIsString($body);
        self::assertStringContainsString('https://runner.example.test/newsletter/desinscription/purpose-signed-token', $body);
        self::assertStringContainsString('sans compte', $body);
        self::assertStringContainsString('stable-id@runnerbib.local', $mail->getHeaders()->toString());
    }
}
