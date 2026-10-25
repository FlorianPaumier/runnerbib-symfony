<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\CredentialMail;
use App\Application\Operations\CredentialMailSender;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final readonly class SymfonyCredentialMailSender implements CredentialMailSender
{
    public function __construct(private TransportInterface $transport, private string $baseUrl, private string $sender)
    {
    }

    public function send(CredentialMail $mail, string $messageId): void
    {
        $path = 'verification' === $mail->purpose ? '/verification/' : '/reinitialiser/';
        $subject = 'verification' === $mail->purpose ? 'Confirmez votre adresse e-mail' : 'Réinitialisez votre mot de passe';
        $email = (new Email())->from($this->sender)->to($mail->email)->subject($subject)->text('Votre lien : '.rtrim($this->baseUrl, '/').$path.$mail->token."\nCe lien expire le ".$mail->expiresAt->format(DATE_ATOM).'.');
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        $this->transport->send($email);
    }
}
