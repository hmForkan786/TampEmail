<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use App\DTOs\Billing\StripeAccount;
use App\Exceptions\Billing\StripeException;
use App\Models\BillingOrder;

final class StripeAccountResolver
{
    public function environment(): string
    {
        $environment = strtolower((string) config('billing.stripe.environment', 'test'));
        if (! in_array($environment, ['test', 'live'], true)) {
            throw new StripeException('Stripe environment configuration is invalid.');
        }

        return $environment;
    }

    public function resolve(?BillingOrder $order = null, ?string $explicitKey = null, bool $requireWebhookSecret = false): StripeAccount
    {
        $metadata = is_array($order?->metadata) ? $order->metadata : [];
        $key = $explicitKey ?? (is_string($metadata['stripe_account_key'] ?? null) ? $metadata['stripe_account_key'] : null)
            ?? (string) config('billing.stripe.default_account', 'default');
        $account = config("billing.stripe.accounts.{$key}");
        if (! is_array($account) || ($account['enabled'] ?? false) !== true) {
            throw new StripeException('Stripe account is unavailable.');
        }
        $environment = $this->environment();
        if (isset($account['environment']) && $account['environment'] !== $environment) {
            throw new StripeException('Stripe account environment mismatch.');
        }
        $secret = (string) ($account['secret_key'] ?? '');
        $expectedPrefix = $environment === 'live' ? 'sk_live_' : 'sk_test_';
        if (! str_starts_with($secret, $expectedPrefix)) {
            throw new StripeException('Stripe account credentials are invalid for the environment.');
        }
        $webhookSecrets = array_values(array_slice(array_filter((array) ($account['webhook_secrets'] ?? []), fn ($value): bool => is_string($value) && str_starts_with($value, 'whsec_')), 0, (int) config('billing.stripe.webhooks.max_secrets_to_try', 2)));
        if ($requireWebhookSecret && $webhookSecrets === []) {
            throw new StripeException('Stripe webhook verification is not configured.');
        }

        return new StripeAccount(
            $key, $environment, $secret,
            is_string($account['publishable_key'] ?? null) ? $account['publishable_key'] : null,
            $webhookSecrets,
            is_string($account['stripe_account'] ?? null) && $account['stripe_account'] !== '' ? $account['stripe_account'] : null,
            is_string(config('billing.stripe.api_version')) && config('billing.stripe.api_version') !== '' ? config('billing.stripe.api_version') : null,
        );
    }

    /** @return list<StripeAccount> */
    public function webhookCandidates(): array
    {
        $candidates = [];
        foreach (array_keys((array) config('billing.stripe.accounts', [])) as $key) {
            try {
                $candidates[] = $this->resolve(explicitKey: (string) $key, requireWebhookSecret: true);
            } catch (StripeException) {
                continue;
            }
        }

        return $candidates;
    }
}
