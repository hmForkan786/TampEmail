<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\Contracts\Billing\ProviderWebhookVerifier;
use LogicException;

final class ProviderWebhookVerifierRegistry
{
    /** @var array<string, ProviderWebhookVerifier> */
    private array $verifiers = [];

    /** @param iterable<ProviderWebhookVerifier> $verifiers */
    public function __construct(iterable $verifiers)
    {
        foreach ($verifiers as $verifier) {
            $provider = strtolower(trim($verifier->provider()));
            if (isset($this->verifiers[$provider])) {
                throw new LogicException("Duplicate webhook verifier registered for [{$provider}].");
            }
            $this->verifiers[$provider] = $verifier;
        }
    }

    public function get(string $provider): ?ProviderWebhookVerifier
    {
        return $this->verifiers[strtolower(trim($provider))] ?? null;
    }
}
