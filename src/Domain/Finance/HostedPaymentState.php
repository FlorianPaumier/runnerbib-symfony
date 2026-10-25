<?php

declare(strict_types=1);

namespace App\Domain\Finance;

enum HostedPaymentState: string
{
    case Open = 'open';
    case Processing = 'processing';
    case Paid = 'paid';
    case Closed = 'closed';
    case Unknown = 'unknown';
}
