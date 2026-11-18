<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use App\DTOs\Billing\StripeAccount;
use App\Exceptions\Billing\StripeException;
use Stripe\StripeClient;
use Throwable;

class StripeApiClient
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function createCheckout(StripeAccount $account, array $parameters, string $idempotencyKey): array
    {
        try {
            return $this->client($account)->checkout->sessions->create($parameters, $this->options($account, $idempotencyKey))->toArray();
        } catch (Throwable) {
            throw new StripeException('Stripe checkout request failed.');
        }
    }

    /** @return array<string, mixed> */
    public function retrieveCheckout(StripeAccount $account, string $sessionId): array
    {
        try {
            return $this->client($account)->checkout->sessions->retrieve($sessionId, ['expand' => ['payment_intent']], $this->options($account))->toArray();
        } catch (Throwable) {
            throw new StripeException('Stripe payment query failed.');
        }
    }

    /** @return array<string, mixed> */
    public function retrieveAccount(StripeAccount $account): array
    {
        try {
            return $this->client($account)->accounts->retrieve($account->connectedAccountId, [], $this->options($account))->toArray();
        } catch (Throwable) {
            throw new StripeException('Stripe health check failed.');
        }
    }

    protected function client(StripeAccount $account): StripeClient
    {
        $config = ['api_key' => $account->secretKey, 'max_network_retries' => (int) config('billing.stripe.api.max_network_retries', 2)];
        if ($account->apiVersion !== null) {
            $config['stripe_version'] = $account->apiVersion;
        }

        return new StripeClient($config);
    }

    /** @return array{idempotency_key?:string,stripe_account?:string} */
    private function options(StripeAccount $account, ?string $idempotencyKey = null): array
    {
        $options = [];
        if ($account->connectedAccountId !== null && $account->connectedAccountId !== '') {
            $options['stripe_account'] = $account->connectedAccountId;
        }
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $options['idempotency_key'] = $idempotencyKey;
        }

        return $options;
    }
}
