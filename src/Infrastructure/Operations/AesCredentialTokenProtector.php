<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\CredentialTokenProtector;

final readonly class AesCredentialTokenProtector implements CredentialTokenProtector
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('The credential token secret must be at least 16 characters long.');
        }
    }

    public function protect(string $token): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($token, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        assert(false !== $cipher);

        return base64_encode($iv.$tag.$cipher);
    }

    public function reveal(string $protected): string
    {
        $raw = base64_decode($protected, true);
        if (false === $raw || strlen($raw) <= 28) {
            throw new \RuntimeException('Protected credential token is unreadable.');
        }
        $token = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if (false === $token) {
            throw new \RuntimeException('Protected credential token is unreadable.');
        }

        return $token;
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'credential-mail-token', $this->secret, true);
    }
}
