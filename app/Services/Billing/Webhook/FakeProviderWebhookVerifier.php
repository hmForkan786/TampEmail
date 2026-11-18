<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\Contracts\Billing\ProviderWebhookVerifier;
use App\DTOs\Billing\ProviderWebhookVerificationContext;
use App\DTOs\Billing\RawWebhookRequest;
use App\DTOs\Billing\WebhookVerificationResult;
use App\Enums\SignatureEncoding;
use App\Enums\WebhookCanonicalizationStrategy;

final class FakeProviderWebhookVerifier implements ProviderWebhookVerifier
{
    public function __construct(
        private readonly HmacSignatureVerifier $hmac,
        private readonly WebhookPayloadCanonicalizer $canonicalizer,
        private readonly WebhookTimestampValidator $timestamps,
    ) {}

    public function provider(): string
    {
        return 'fake';
    }

    public function supportsSignatureVersion(string $version): bool
    {
        return $version === 'v1';
    }

    public function verify(RawWebhookRequest $request, ProviderWebhookVerificationContext $context): WebhookVerificationResult
    {
        $payloadHash = hash('sha256', $request->rawBody);
        $signatureHeader = $request->header('x-fake-signature');
        $timestamp = $request->header('x-fake-timestamp');
        $nonce = $request->header('x-fake-nonce');
        if ($signatureHeader === null) {
            return $this->failure($request, $payloadHash, 'missing_signature');
        }
        if (! preg_match('/\A(v\d+)=([0-9a-f]+)\z/D', $signatureHeader, $parts)) {
            return $this->failure($request, $payloadHash, 'malformed_signature');
        }
        if (! $this->supportsSignatureVersion($parts[1])) {
            return $this->failure($request, $payloadHash, 'unsupported_signature_version');
        }
        if ($nonce === null) {
            return $this->failure($request, $payloadHash, 'missing_nonce');
        }
        $time = $this->timestamps->validate($timestamp, $context->replayWindowSeconds, $context->allowedClockSkewSeconds);
        if (! $time['valid']) {
            return $this->failure($request, $payloadHash, match ($time['code']) {
                'missing' => 'missing_timestamp', 'too_old' => 'timestamp_too_old',
                'too_far_in_future' => 'timestamp_in_future', default => 'invalid_timestamp',
            });
        }
        $canonical = $this->canonicalizer->canonicalize($request, WebhookCanonicalizationStrategy::TimestampNonceRawBody, $timestamp, $nonce);
        $matches = [];
        foreach ($context->activeSigningKeys as $key) {
            if ($this->hmac->verify($key['algorithm'], $key['secret'], $canonical->bytes, $parts[2], SignatureEncoding::Hex)) {
                $matches[] = $key['id'];
            }
        }
        if (count($matches) !== 1) {
            return $this->failure($request, $payloadHash, count($matches) > 1 ? 'multiple_signing_keys_matched' : 'signature_mismatch', $canonical->hash);
        }
        $decoded = json_decode($request->rawBody, true);

        return new WebhookVerificationResult(true, $request->provider, $parts[1], $matches[0], $time['timestamp'], $nonce, is_array($decoded) ? ($decoded['event_id'] ?? null) : null, $payloadHash, $canonical->hash, null);
    }

    private function failure(RawWebhookRequest $request, string $payloadHash, string $code, ?string $canonicalHash = null): WebhookVerificationResult
    {
        return new WebhookVerificationResult(false, $request->provider, null, null, null, null, null, $payloadHash, $canonicalHash, $code);
    }
}
