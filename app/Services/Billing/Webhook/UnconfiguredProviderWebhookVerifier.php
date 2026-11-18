<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\Contracts\Billing\ProviderWebhookVerifier;
use App\DTOs\Billing\ProviderWebhookVerificationContext;
use App\DTOs\Billing\RawWebhookRequest;
use App\DTOs\Billing\WebhookVerificationResult;

final readonly class UnconfiguredProviderWebhookVerifier implements ProviderWebhookVerifier
{
    public function __construct(private string $name) {}

    public function provider(): string
    {
        return $this->name;
    }

    public function supportsSignatureVersion(string $version): bool
    {
        return false;
    }

    public function verify(RawWebhookRequest $request, ProviderWebhookVerificationContext $context): WebhookVerificationResult
    {
        return new WebhookVerificationResult(false, $this->name, null, null, null, null, null, hash('sha256', $request->rawBody), null, 'verification_adapter_not_configured');
    }
}
