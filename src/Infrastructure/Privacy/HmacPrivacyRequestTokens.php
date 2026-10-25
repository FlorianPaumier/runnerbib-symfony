<?php

declare(strict_types=1);

namespace App\Infrastructure\Privacy;

use App\Application\Privacy\PrivacyProgressAuthority;
use App\Application\Privacy\PrivacyRequestTokens;

final readonly class HmacPrivacyRequestTokens implements PrivacyRequestTokens
{
    public function __construct(private string $secret)
    {
        if ('' === $secret) {
            throw new \InvalidArgumentException('Privacy signing secret is required.');
        }
    }

    public function issue(string $requestId, string $accountId, int $accountVersion, \DateTimeImmutable $expiresAt): string
    {
        $payload = rtrim(strtr(base64_encode($requestId.'|'.$accountId.'|'.$accountVersion.'|'.$expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.uP')), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', "privacy.request_status\0".$payload, $this->secret);
    }

    public function verify(string $token, \DateTimeImmutable $now): PrivacyProgressAuthority
    {
        $parts = explode('.', $token);
        if (2 !== count($parts) || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0]) || !hash_equals(hash_hmac('sha256', "privacy.request_status\0".$parts[0], $this->secret), $parts[1])) {
            throw new \InvalidArgumentException('Privacy status authority is invalid.');
        }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Privacy status authority is invalid.');
        }
        $payload = explode('|', $decoded);
        if (4 !== count($payload)) {
            throw new \InvalidArgumentException('Privacy status authority is invalid.');
        }
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $payload[0]) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $payload[1])) {
            throw new \InvalidArgumentException('Privacy status authority is invalid.');
        }
        $version = filter_var($payload[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $version) {
            throw new \InvalidArgumentException('Privacy status authority is invalid.');
        }
        $expiresAt = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s.uP', $payload[3]);
        if (false === $expiresAt || $expiresAt->format('Y-m-d\\TH:i:s.uP') !== $payload[3] || $now >= $expiresAt) {
            throw new \InvalidArgumentException('Privacy status authority is invalid.');
        }

        return new PrivacyProgressAuthority($payload[0], $payload[1], $version, $expiresAt);
    }
}
