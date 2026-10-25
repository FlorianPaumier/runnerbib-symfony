<?php

declare(strict_types=1);

namespace App\Application\Checkout;

use App\Application\Finance\SubmitHostedCheckout;

final readonly class StartCheckout
{
    public function __construct(private AuthorizeCheckout $authorization, private SubmitHostedCheckout $submission)
    {
    }

    /**
     * @param array<string,\stdClass> $registration
     * @param list<string>            $acknowledgedRaces
     */
    public function start(string $buyerId, array $registration, array $acknowledgedRaces, \DateTimeImmutable $now): CheckoutStart
    {
        $acknowledgments = [];
        foreach ($acknowledgedRaces as $raceId) {
            $acknowledgments[$raceId] = $this->authorization->acknowledgmentVersion();
        }
        $this->submission->assertConfigured();
        $view = $this->authorization->authorize($buyerId, $registration, $acknowledgments, $now);
        try {
            $session = $this->submission->submit($view->operationId, $view->checkoutId, $now, 'checkout-web');
        } catch (\RuntimeException|\DomainException) {
            // The checkout stays protected and is reconciled later; the buyer sees its authoritative state.
            return new CheckoutStart($view->checkoutId, null);
        }

        return new CheckoutStart($view->checkoutId, $session->url);
    }
}
