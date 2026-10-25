<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Application\Finance\HostedCheckoutIntent;
use App\Application\Finance\HostedCheckoutProvider;
use App\Application\Finance\HostedCheckoutSession;

final class ControlledHostedCheckoutProvider implements HostedCheckoutProvider
{
    /** @var list<string> */
    public array $keys = [];
    /** @var list<string> */
    public array $retrieved = [];
    public \App\Domain\Finance\HostedPaymentState $lifecycle = \App\Domain\Finance\HostedPaymentState::Open;
    /** @var list<string> */
    public array $inspected = [];
    /** @var \Closure():void|null */
    public ?\Closure $onInspection = null;

    public function inspect(\App\Application\Finance\CheckoutReconciliation $claim): \App\Domain\Finance\HostedPaymentState
    {
        $this->inspected[] = $claim->sessionReference ?? '';
        if (null !== $this->onInspection) {
            ($this->onInspection)();
        }

        return $this->lifecycle;
    }
    /** @var list<string> */
    public array $expirations = [];
    /** @var \Closure():void|null */
    public ?\Closure $onExpiration = null;

    public function expire(\App\Application\Finance\CheckoutReconciliation $claim, string $idempotencyKey): void
    {
        $this->expirations[] = $idempotencyKey;
        if (null !== $this->onExpiration) {
            ($this->onExpiration)();
        }
        $this->lifecycle = \App\Domain\Finance\HostedPaymentState::Closed;
    }
    public bool $failNext = false;
    /** @var \Closure():void|null */
    public ?\Closure $onCreation = null;

    public function retrieve(string $sessionReference): HostedCheckoutSession
    {
        $this->retrieved[] = $sessionReference;

        return new HostedCheckoutSession($sessionReference, 'https://checkout.stripe.test/session');
    }

    public bool $failConfiguration = false;

    public function assertConfigured(): void
    {
        if ($this->failConfiguration) {
            throw new \RuntimeException('Controlled configuration failure.');
        }
    }

    public function create(HostedCheckoutIntent $intent, string $idempotencyKey): HostedCheckoutSession
    {
        $this->keys[] = $idempotencyKey;
        if (null !== $this->onCreation) {
            ($this->onCreation)();
        }
        if ($this->failNext) {
            $this->failNext = false;
            throw new \RuntimeException('Controlled provider response lost.');
        }

        return new HostedCheckoutSession('cs_hosted', 'https://checkout.stripe.test/session');
    }
}
