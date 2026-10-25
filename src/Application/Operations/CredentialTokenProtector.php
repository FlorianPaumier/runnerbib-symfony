<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface CredentialTokenProtector
{
    /**
     * Protects a credential token so it is never stored in clear text.
     */
    public function protect(string $token): string;

    /**
     * Returns the original token.
     *
     * @throws \RuntimeException when the protected token cannot be read
     */
    public function reveal(string $protected): string;
}
