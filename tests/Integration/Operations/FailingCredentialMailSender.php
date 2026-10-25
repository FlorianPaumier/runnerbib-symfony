<?php

declare(strict_types=1);

namespace App\Tests\Integration\Operations;

use App\Application\Operations\CredentialMail;
use App\Application\Operations\CredentialMailSender;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FailingCredentialMailSender implements CredentialMailSender
{
    public int $calls = 0;

    public function __construct(private Connection $connection)
    {
    }

    public function send(CredentialMail $mail, string $messageId): void
    {
        TestCase::assertFalse($this->connection->isTransactionActive());
        ++$this->calls;
        throw new \RuntimeException('Private SMTP exception details');
    }
}
