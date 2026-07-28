<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\DTOs\Billing\ProviderWebhookVerificationContext;
use App\DTOs\Billing\RawWebhookRequest;
use App\DTOs\Billing\WebhookVerificationResult;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\Cache;

final readonly class ProviderWebhookVerificationService
{
    public function __construct(
        private ProviderWebhookVerifierResolver $resolver,
        private ProviderSigningKeyResolver $keys,
        private WebhookReplayProtectionService $replay,
        private AuditLogWriter $audit,
    ) {}

    public function verify(RawWebhookRequest $request): WebhookVerificationResult
    {
        if (! config('billing.webhook_security.enabled', true)) {
            return $this->record($request, new WebhookVerificationResult(false, $request->provider, null, null, null, null, null, hash('sha256', $request->rawBody), null, 'verification_configuration_invalid'));
        }
        $config = (array) config("billing.webhook_security.providers.{$request->provider}", []);
        $verifier = $this->resolver->resolve($request->provider);
        if ($verifier === null) {
            return $this->record($request, new WebhookVerificationResult(false, $request->provider, null, null, null, null, null, hash('sha256', $request->rawBody), null, 'provider_unknown'));
        }
        if (($config['enabled'] ?? false) !== true) {
            return $this->record($request, new WebhookVerificationResult(false, $request->provider, null, null, null, null, null, hash('sha256', $request->rawBody), null, 'provider_disabled'));
        }
        $environment = (string) config('billing.webhook_security.environment', 'test');
        $context = new ProviderWebhookVerificationContext(
            $request->provider,
            (int) config('billing.webhook_security.allowed_future_clock_skew_seconds', 60),
            (int) config('billing.webhook_security.default_replay_window_seconds', 300),
            $this->keys->resolve($request->provider, $environment),
            null, $environment, (array) ($config['required_headers'] ?? []), 'v1',
        );
        $result = $verifier->verify($request, $context);
        if ($result->verified && $result->nonce !== null) {
            $classification = $this->replay->reserve($request->provider, $result->nonce, $result->payloadHash, $result->matchedKeyId, $request->sourceIp, $context->replayWindowSeconds);
            if (! in_array($classification, ['first_seen', 'exact_retry'], true)) {
                $result = new WebhookVerificationResult(false, $request->provider, $result->signatureVersion, $result->matchedKeyId, $result->signedAt, null, $result->providerEventId, $result->payloadHash, $result->canonicalPayloadHash, $classification === 'invalid_nonce' ? 'invalid_nonce' : 'replayed_nonce', ['classification' => $classification]);
            }
        }

        return $this->record($request, $result);
    }

    private function record(RawWebhookRequest $request, WebhookVerificationResult $result): WebhookVerificationResult
    {
        $outcome = $result->verified ? 'verified' : 'rejected';
        Cache::increment("billing:webhook-security:{$request->provider}:{$outcome}");
        $this->audit->write("billing.webhook.{$outcome}", null, null, null, [
            'provider' => $request->provider, 'request_id' => $request->requestId,
            'failure_code' => $result->failureCode, 'payload_hash' => $result->payloadHash,
            'key_id' => $result->matchedKeyId,
        ]);

        return $result;
    }
}
