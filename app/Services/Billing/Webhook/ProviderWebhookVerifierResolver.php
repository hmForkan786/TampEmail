<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\Contracts\Billing\ProviderWebhookVerifier;

final readonly class ProviderWebhookVerifierResolver
{
    public function __construct(private ProviderWebhookVerifierRegistry $registry) {}

    public function resolve(string $provider): ?ProviderWebhookVerifier
    {
        $provider = strtolower(trim($provider));
        if (! preg_match('/\A[a-z0-9_-]{2,40}\z/D', $provider)) {
            return null;
        }

        return $this->registry->get($provider);
    }
}
