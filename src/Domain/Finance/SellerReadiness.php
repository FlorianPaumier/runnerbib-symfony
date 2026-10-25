<?php

declare(strict_types=1);

namespace App\Domain\Finance;

enum SellerReadiness: string
{
    case Ready = 'ready';
    case Pending = 'pending';
    case Restricted = 'restricted';

    public static function fromRecipient(string $transferStatus, bool $requirementsOutstanding = false): self
    {
        if ($requirementsOutstanding && in_array($transferStatus, ['active', 'pending'], true)) {
            return self::Pending;
        }

        return match ($transferStatus) {
            'active' => self::Ready,
            'pending' => self::Pending,
            default => self::Restricted,
        };
    }

    public function canPublish(): bool
    {
        return self::Ready === $this;
    }

    public function canTransfer(): bool
    {
        return self::Ready === $this;
    }
}
