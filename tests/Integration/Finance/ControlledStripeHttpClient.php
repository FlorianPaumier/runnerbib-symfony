<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use Stripe\HttpClient\ClientInterface;

final class ControlledStripeHttpClient implements ClientInterface
{
    public mixed $hostedUrl = 'https://connect.stripe.com/setup/controlled';
    public bool $failRequests = false;

    /** @var array<string, mixed> */
    public array $account = ['id' => 'acct_controlled', 'object' => 'v2.core.account', 'closed' => false, 'configuration' => ['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['status' => 'active', 'status_details' => []]]]]], 'requirements' => ['entries' => []]];

    /** @var list<array{method: string, url: string, params: mixed, headers: mixed}> */
    public array $requests = [];

    /** @param array<string> $headers
     * @param array<array-key, mixed> $params
     *
     * @return array{string, int, array<string, string>}
     */
    public function request(mixed $method, mixed $absUrl, mixed $headers, mixed $params, mixed $hasFile, mixed $apiMode = 'v1', mixed $maxNetworkRetries = null): array
    {
        if ($this->failRequests) {
            throw \Stripe\Exception\ApiConnectionException::factory('Controlled transport unavailable.');
        }
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params, 'headers' => $headers];
        $body = str_ends_with($absUrl, '/balance_settings') ? ['object' => 'balance_settings', 'payments' => ['payouts' => ['schedule' => ['interval' => 'daily']]]] : $this->account;

        if (str_ends_with($absUrl, '/account_links')) {
            $body = ['object' => 'v2.core.account_link', 'url' => $this->hostedUrl];
        }

        return [json_encode($body, JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_controlled']];
    }
}
