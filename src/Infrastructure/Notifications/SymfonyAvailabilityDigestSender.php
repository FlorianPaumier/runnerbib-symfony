<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\AvailabilityDigest;
use App\Application\Notifications\AvailabilityDigestSender;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final readonly class SymfonyAvailabilityDigestSender implements AvailabilityDigestSender
{
    public function __construct(private TransportInterface $transport, private string $baseUrl, private string $sender)
    {
    }

    public function send(AvailabilityDigest $digest, string $messageId): void
    {
        $lines = ['Des dossards sont disponibles. Chaque offre conserve sa propre échéance de priorité :'];
        foreach ($digest->offers as $offer) {
            $lines[] = intdiv($offer['price_cents'], 100).','.str_pad((string) ($offer['price_cents'] % 100), 2, '0', STR_PAD_LEFT).' EUR — '.rtrim($this->baseUrl, '/').'/dossards/'.$offer['listing_id'].' — Fin de priorité : '.$offer['deadline']->format(DATE_ATOM);
        }
        $email = (new Email())->from($this->sender)->to($digest->email)->subject('Des dossards sont disponibles')->text(implode("\n", $lines));
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        $this->transport->send($email);
    }
}
