<?php

declare(strict_types=1);

namespace App\Application\Checkout;

use App\Application\Availability\FindOfferAccess;
use App\Application\Operations\OutboxMessage;
use App\Application\Operations\OutboxWriter;
use App\Application\Operations\TransactionManager;
use App\Domain\Checkout\CartQuote;
use App\Domain\Finance\FeePolicy;
use App\Domain\Registration\RegistrationSchema;

final readonly class AuthorizeCheckout
{
    /** @param array<string,mixed> $providerConfiguration */
    public function __construct(private CheckoutAuthorizationStore $store, private FindOfferAccess $access, private TransactionManager $transactions, private OutboxWriter $outbox, private FeePolicy $fees, private string $scope, private string $feePolicyVersion, private array $providerConfiguration, private string $acknowledgmentVersion)
    {
        if ('' === trim($scope) || '' === trim($feePolicyVersion)) {
            throw new \DomainException('Checkout provider and fee policy configuration is required.');
        }
        if (array_diff(array_keys($providerConfiguration), ['success_url', 'cancel_url', 'api_version', 'automatic_tax']) || false !== ($providerConfiguration['automatic_tax'] ?? null) || !is_string($providerConfiguration['api_version'] ?? null) || '' === trim($providerConfiguration['api_version'])) {
            throw new \DomainException('Checkout provider configuration is incomplete.');
        }
    }

    public function acknowledgmentVersion(): string
    {
        return $this->acknowledgmentVersion;
    }

    /**
     * @param array<string,\stdClass> $registration
     * @param array<string,string>    $acknowledgments
     */
    public function authorize(string $buyerId, array $registration, array $acknowledgments, \DateTimeImmutable $now): CheckoutAuthorizationView
    {
        foreach (['success_url', 'cancel_url'] as $key) {
            $url = $this->providerConfiguration[$key] ?? null;
            if (!is_string($url) || false === filter_var($url, FILTER_VALIDATE_URL) || 'https' !== parse_url($url, PHP_URL_SCHEME)) {
                throw new \DomainException('Checkout callbacks require secure URLs.');
            }
        }
        if ([] !== $this->store->unreadySellers($buyerId, $this->scope)) {
            throw new \DomainException('Seller cannot receive transfers.');
        }
        $view = null;
        $this->transactions->run(function () use ($buyerId, $registration, $acknowledgments, $now, &$view): void {
            $rows = $this->store->lockCart($buyerId, $this->scope);
            $identity = $this->store->identity($buyerId);
            $checkoutId = $this->store->id();
            $operationId = $this->store->id();
            $deadline = $now->add(new \DateInterval('PT900S'));
            $items = [];
            $parameters = [];
            $quote = new CartQuote($buyerId, $this->fees);
            $access = $this->access->forListings(array_values(array_unique(array_column($rows, 'listingId'))), $buyerId, $now);
            foreach ($rows as $row) {
                $listing = $row->listingId;
                $race = $row->raceId;
                $offer = $access[$listing] ?? null;
                if (null === $offer || !$offer->canInitiatePurchase) {
                    throw new \DomainException('Listing cannot be authorized.');
                }
                if (!isset($registration[$listing])) {
                    throw new \DomainException('Registration input is required.');
                }
                $schema = $row->registrationSchema;
                if (null === $schema && [] !== get_object_vars($registration[$listing])) {
                    throw new \DomainException('Unrequested registration fields are not accepted.');
                }
                if (null !== $schema && [] !== (new RegistrationSchema($schema))->violations($registration[$listing])) {
                    throw new \DomainException('Registration answers are invalid.');
                }
                if ('nonparticipating' === $row->participation && ('' === trim($this->acknowledgmentVersion) || $this->acknowledgmentVersion !== ($acknowledgments[$race] ?? null))) {
                    throw new \DomainException('Explicit transfer acknowledgment is required.');
                }
                $fee = $this->fees->quote($offer->priceCents);
                $quote->add($race, $row->sellerId, $offer->priceCents);
                $itemId = $this->store->id();
                $items[] = ['id' => $itemId, 'checkout_id' => $checkoutId, 'race_id' => $race, 'entitlement_id' => $row->entitlementId, 'listing_id' => $listing, 'seller_id' => $row->sellerId, 'availability_event_id' => $offer->eventId, 'offer_version' => $offer->offerVersion, 'price_cents' => $fee->priceCents, 'buyer_fee_cents' => $fee->buyerFeeCents, 'seller_margin_cents' => $fee->sellerMarginCents, 'buyer_total_cents' => $fee->buyerTotalCents, 'seller_net_cents' => $fee->sellerNetCents, 'currency' => 'EUR', 'fee_policy_version' => $this->feePolicyVersion, 'registration_schema_version' => $row->registrationSchemaVersion, 'registration_schema' => null === $schema ? null : json_encode($schema, JSON_THROW_ON_ERROR), 'buyer_name' => $identity['display_name'], 'buyer_email' => $identity['email'], 'registration_fields' => json_encode($registration[$listing], JSON_THROW_ON_ERROR), 'participation_snapshot' => $row->participation, 'acknowledgment_text_version' => $acknowledgments[$race] ?? null, 'acknowledgment_accepted_at' => isset($acknowledgments[$race]) ? $now->format(DATE_ATOM) : null, 'initial_expires_at' => $deadline->format(DATE_ATOM)];
                $parameters[] = ['checkout_item_id' => $itemId, 'listing_id' => $listing, 'price_cents' => $fee->priceCents, 'buyer_fee_cents' => $fee->buyerFeeCents, 'buyer_total_cents' => $fee->buyerTotalCents];
            }
            $json = json_encode(['items' => $parameters, 'fee_policy_version' => $this->feePolicyVersion, 'provider_configuration' => $this->providerConfiguration], JSON_THROW_ON_ERROR);
            $view = new CheckoutAuthorizationView($checkoutId, $deadline, $quote->buyerTotalCents(), 'EUR', $operationId);
            $this->store->commit($view, $buyerId, $now, $items, $json, $this->scope);
            $this->outbox->append(new OutboxMessage($this->store->id(), 'checkout:'.$checkoutId.':payment_prepared', 'checkout', $checkoutId, 1, 'checkout.payment_prepared', 1, json_encode(['operation_id' => $operationId], JSON_THROW_ON_ERROR), $now));
        });
        assert($view instanceof CheckoutAuthorizationView);

        return $view;
    }
}
