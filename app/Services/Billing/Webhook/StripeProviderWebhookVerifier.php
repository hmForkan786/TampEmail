<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\Contracts\Billing\ProviderWebhookVerifier;
use App\DTOs\Billing\ProviderWebhookVerificationContext;
use App\DTOs\Billing\RawWebhookRequest;
use App\DTOs\Billing\WebhookVerificationResult;
use App\Services\Billing\Stripe\StripeAccountResolver;
use Stripe\Webhook;

final readonly class StripeProviderWebhookVerifier implements ProviderWebhookVerifier
{
    public function __construct(private StripeAccountResolver $accounts) {}

    public function provider(): string
    {
        return 'stripe';
    }

    public function supportsSignatureVersion(string $version): bool
    {
        return $version === 'v1';
    }

    public function verify(RawWebhookRequest $request, ProviderWebhookVerificationContext $context): WebhookVerificationResult
    {
        $hash = hash('sha256', $request->rawBody);
        $signature = $request->header('stripe-signature');
        if ($signature === null) {
            return $this->failed($hash, 'missing_signature');
        }
        $maximum = (int) config('billing.stripe.webhooks.max_secrets_to_try', 2);
        $matches = [];
        foreach ($this->accounts->webhookCandidates() as $account) {
            foreach (array_slice($account->webhookSecrets, 0, $maximum) as $secret) {
                try {
                    $event = Webhook::constructEvent($request->rawBody, $signature, $secret, (int) config('billing.stripe.webhooks.tolerance_seconds', 300));
                    $array = $event->toArray();
                    if ($account->connectedAccountId !== null && ($array['account'] ?? null) !== $account->connectedAccountId) {
                        continue;
                    }
                    $matches[] = [$account, $array];
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        if (count($matches) !== 1) {
            return $this->failed($hash, count($matches) > 1 ? 'multiple_signing_keys_matched' : 'signature_mismatch');
        }
        [$account, $event] = $matches[0];
        $eventId = (string) ($event['id'] ?? '');
        if ($eventId === '') {
            return $this->failed($hash, 'canonicalization_failed');
        }

        return new WebhookVerificationResult(
            true, 'stripe', 'v1', $account->key, now()->toDateTimeImmutable(), $eventId, $eventId,
            $hash, $hash, null, verificationMetadata: ['account_key' => $account->key],
        );
    }

    private function failed(string $hash, string $code): WebhookVerificationResult
    {
        return new WebhookVerificationResult(false, 'stripe', 'v1', null, null, null, null, $hash, null, $code);
    }
}
