<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final readonly class Signup
{
    public string $email;

    public function __construct(string $email, public string $password, bool $termsAccepted)
    {
        $this->email = strtolower(trim($email));
        if (false === filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse courriel invalide.');
        }
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir au moins 12 caractères.');
        }
        if (!$termsAccepted) {
            throw new \InvalidArgumentException('Vous devez accepter les conditions.');
        }
    }
}
