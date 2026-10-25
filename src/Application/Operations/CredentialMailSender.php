<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface CredentialMailSender
{
    public function send(CredentialMail $mail, string $messageId): void;
}
