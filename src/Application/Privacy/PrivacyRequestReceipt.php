<?php

declare(strict_types=1);

namespace App\Application\Privacy;

final readonly class PrivacyRequestReceipt
{
    public function __construct(public PrivacyRequestRecord $request, public string $statusToken)
    {
    }
}
