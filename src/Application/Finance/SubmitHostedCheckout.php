<?php

declare(strict_types=1);

namespace App\Application\Finance;

final readonly class SubmitHostedCheckout
{
    public function __construct(private PaymentSubmissionStore $store, private HostedCheckoutProvider $provider)
    {
    }

    public function assertConfigured(): void
    {
        $this->provider->assertConfigured();
    }

    public function submit(string $operationId, string $checkoutId, \DateTimeImmutable $now, string $worker): HostedCheckoutSession
    {
        $this->provider->assertConfigured();
        $intent = $this->store->prepare($operationId, $checkoutId, $now, $worker);
        if (null !== $intent->sessionReference) {
            return $this->provider->retrieve($intent->sessionReference);
        }
        try {
            $session = $this->provider->create($intent, 'checkout-payment:'.$operationId);
        } catch (\RuntimeException $exception) {
            $this->store->unknown($intent);
            throw $exception;
        }
        $this->store->recordSession($intent, $session, $now);

        return $session;
    }
}
