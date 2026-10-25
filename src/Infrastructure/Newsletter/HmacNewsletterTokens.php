<?php

declare(strict_types=1);

namespace App\Infrastructure\Newsletter;

use App\Application\Newsletter\NewsletterTokens;

final readonly class HmacNewsletterTokens implements NewsletterTokens
{
    /** @var list<string> */
    private array $previousSecrets;

    /** @param array<array-key,mixed> $previousSecrets */
    public function __construct(private string $secret, array $previousSecrets = [])
    {
        if ('' === $secret) {
            throw new \InvalidArgumentException('Newsletter signing secret is required.');
        }
        if (!array_is_list($previousSecrets)) {
            throw new \InvalidArgumentException('Previous newsletter secrets must be a list.');
        }
        $keys = [];
        foreach ($previousSecrets as $previousSecret) {
            if (!is_string($previousSecret) || '' === $previousSecret) {
                throw new \InvalidArgumentException('Previous newsletter secrets must be nonempty strings.');
            }
            $keys[] = $previousSecret;
        }
        $this->previousSecrets = $keys;
    }

    public function verify(string $token): \App\Application\Newsletter\NewsletterUnsubscribeAuthority
    {
        $parts = explode('.', $token);
        if (2 !== count($parts) || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0])) {
            throw new \InvalidArgumentException('Lien de désinscription invalide.');
        }
        $verified = false;
        foreach ([$this->secret, ...$this->previousSecrets] as $secret) {
            if (hash_equals(hash_hmac('sha256', "newsletter.unsubscribe\0".$parts[0], $secret), $parts[1])) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            throw new \InvalidArgumentException('Lien de désinscription invalide.');
        }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Lien de désinscription invalide.');
        }
        $payload = explode('|', $decoded);
        if (2 !== count($payload) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $payload[0])) {
            throw new \InvalidArgumentException('Lien de désinscription invalide.');
        }
        $joinedAt = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s.uP', $payload[1]);
        if (false === $joinedAt || $joinedAt->format('Y-m-d\\TH:i:s.uP') !== $payload[1]) {
            throw new \InvalidArgumentException('Lien de désinscription invalide.');
        }

        return new \App\Application\Newsletter\NewsletterUnsubscribeAuthority($payload[0], $joinedAt);
    }

    public function issue(string $subscriptionId, \DateTimeImmutable $joinedAt): string
    {
        $payload = rtrim(strtr(base64_encode($subscriptionId.'|'.$joinedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.uP')), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', "newsletter.unsubscribe\0".$payload, $this->secret);
    }
}
