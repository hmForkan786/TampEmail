<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Models\WebhookDelivery;

/** Deterministic HMAC signing contract for outbound webhook deliveries. */
final class WebhookSignatureService
{
    /**
     * @return array<string, string>
     */
    public function headers(WebhookDelivery $delivery, string $secret, string $rawBody, ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();
        $signature = $this->sign($secret, $timestamp, $rawBody);

        return [
            'X-Webhook-Id' => $delivery->event_id,
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Signature' => 'sha256='.$signature,
            'X-Webhook-Event-Type' => $delivery->event_type,
            'X-Webhook-Delivery-Id' => (string) $delivery->getKey(),
            'X-Webhook-Attempt' => (string) max(1, $delivery->attempt_count + 1),
            'Content-Type' => 'application/json',
        ];
    }

    public function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    public function verify(string $secret, int $timestamp, string $rawBody, string $providedSignature): bool
    {
        $normalized = str_starts_with($providedSignature, 'sha256=')
            ? substr($providedSignature, 7)
            : $providedSignature;

        return hash_equals($this->sign($secret, $timestamp, $rawBody), $normalized);
    }
}
