<?php

declare(strict_types=1);

namespace App\Contracts\Billing;

use App\DTOs\Billing\ProviderWebhookVerificationContext;
use App\DTOs\Billing\RawWebhookRequest;
use App\DTOs\Billing\WebhookVerificationResult;

interface ProviderWebhookVerifier
{
    public function provider(): string;

    public function verify(RawWebhookRequest $request, ProviderWebhookVerificationContext $context): WebhookVerificationResult;

    public function supportsSignatureVersion(string $version): bool;
}
