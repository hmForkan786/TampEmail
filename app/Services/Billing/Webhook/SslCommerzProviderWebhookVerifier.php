<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\Contracts\Billing\ProviderWebhookVerifier;
use App\DTOs\Billing\ProviderWebhookVerificationContext;
use App\DTOs\Billing\RawWebhookRequest;
use App\DTOs\Billing\WebhookVerificationResult;
use App\Services\Billing\Payload\ProviderPayloadParserRegistry;
use App\Services\Billing\SslCommerz\SslCommerzValidationClient;

final readonly class SslCommerzProviderWebhookVerifier implements ProviderWebhookVerifier
{
    public function __construct(
        private ProviderPayloadParserRegistry $parsers,
        private SslCommerzValidationClient $validation,
    ) {}

    public function provider(): string
    {
        return 'sslcommerz';
    }

    public function supportsSignatureVersion(string $version): bool
    {
        return $version === 'validation-api-v4';
    }

    public function verify(RawWebhookRequest $request, ProviderWebhookVerificationContext $context): WebhookVerificationResult
    {
        $hash = hash('sha256', $request->rawBody);
        try {
            $parser = $this->parsers->resolve($this->provider(), $request->contentType);
            if ($parser === null) {
                return $this->failed($hash, 'unsupported_content_type');
            }
            $payload = $parser->parse($request->rawBody, $request->contentType);
            foreach (['val_id', 'tran_id', 'status', 'value_a'] as $required) {
                if (trim($payload[$required] ?? '') === '') {
                    return $this->failed($hash, 'canonicalization_failed');
                }
            }
            $valid = $this->validation->validateIpn($payload);

            return new WebhookVerificationResult(
                true, $this->provider(), 'validation-api-v4', (string) $valid['store_key'], now()->toDateTimeImmutable(),
                (string) $valid['val_id'], 'sslcommerz_'.hash('sha256', (string) $valid['val_id'].'|'.(string) $valid['tran_id']),
                $hash, hash('sha256', json_encode([$valid['tran_id'], $valid['amount_minor'], $valid['currency'], $valid['status_text']], JSON_THROW_ON_ERROR)), null,
                verificationMetadata: ['validation' => 'server_to_server'],
            );
        } catch (\Throwable) {
            return $this->failed($hash, 'validation_rejected');
        }
    }

    private function failed(string $hash, string $code): WebhookVerificationResult
    {
        return new WebhookVerificationResult(false, $this->provider(), 'validation-api-v4', null, null, null, null, $hash, null, $code);
    }
}
