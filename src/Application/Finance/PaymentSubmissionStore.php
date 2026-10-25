<?php

declare(strict_types=1);

namespace App\Application\Finance;

interface PaymentSubmissionStore
{
    public function prepare(string $operationId, string $checkoutId, \DateTimeImmutable $now, string $worker): HostedCheckoutIntent;

    public function unknown(HostedCheckoutIntent $intent): void;

    public function recordSession(HostedCheckoutIntent $intent, HostedCheckoutSession $session, \DateTimeImmutable $now): void;
}
