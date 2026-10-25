<?php

declare(strict_types=1);

namespace App\Infrastructure\Newsletter;

use App\Application\Newsletter\NewsletterMailSender;
use App\Application\Newsletter\NewsletterSubscription;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final readonly class SymfonyNewsletterMailSender implements NewsletterMailSender
{
    public function __construct(private TransportInterface $transport, private string $baseUrl, private string $sender)
    {
    }

    public function send(NewsletterSubscription $subscription, string $unsubscribeToken, string $messageId): void
    {
        $url = rtrim($this->baseUrl, '/').'/newsletter/desinscription/'.$unsubscribeToken;
        $email = (new Email())->from($this->sender)->to($subscription->email)->subject('Bienvenue à la lettre RelaisDossard')->text("Votre inscription à la lettre RelaisDossard est confirmée. Cette lettre est indépendante des alertes de course.\nVous pouvez vous désinscrire en un clic, sans compte ni motif obligatoire : ".$url);
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        $this->transport->send($email);
    }
}
