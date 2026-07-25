<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundProviderEventParserInterface;
use App\DTOs\Outbound\OutboundProviderEventData;
use App\Enums\OutboundProviderEventType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Generic HMAC-signed outbound delivery event parser for tests and first provider.
 *
 * Canonical signature string: {provider}.{timestamp}.{raw_body}
 */
final class GenericOutboundProviderEventParser implements OutboundProviderEventParserInterface
{
    public function supports(string $provider): bool
    {
        $providers = config('outbound.delivery_webhook.providers', []);

        return $provider === 'generic' && is_array($providers) && array_key_exists('generic', $providers);
    }

    public function verifySignature(Request $request, string $provider, string $rawBody): bool
    {
        $timestamp = trim((string) $request->header('X-Outbound-Timestamp', ''));
        $signature = trim((string) $request->header('X-Outbound-Signature', ''));
        $secret = (string) (config("outbound.delivery_webhook.providers.{$provider}.secret") ?? '');

        if ($secret === '' || $signature === '' || $timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $skew = (int) config('outbound.delivery_webhook.timestamp_skew_seconds', 300);
        if (abs(time() - (int) $timestamp) > $skew) {
            return false;
        }

        $expected = hash_hmac('sha256', $provider.'.'.$timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    public function parse(Request $request, string $provider, string $rawBody): OutboundProviderEventData
    {
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            throw new InvalidArgumentException('invalid_payload');
        }

        $eventId = trim((string) ($payload['event_id'] ?? $request->header('X-Outbound-Event-Id', '')));
        $providerMessageId = trim((string) ($payload['provider_message_id'] ?? ''));
        $type = strtolower(trim((string) ($payload['event_type'] ?? 'unknown')));

        if ($eventId === '') {
            throw new InvalidArgumentException('invalid_payload');
        }

        $eventType = match ($type) {
            'accepted' => OutboundProviderEventType::Accepted,
            'delivered' => OutboundProviderEventType::Delivered,
            'temporary_failure', 'deferred' => OutboundProviderEventType::TemporaryFailure,
            'permanent_failure', 'failed' => OutboundProviderEventType::PermanentFailure,
            'bounced', 'bounce' => OutboundProviderEventType::Bounced,
            'complained', 'complaint' => OutboundProviderEventType::Complained,
            'rejected' => OutboundProviderEventType::Rejected,
            default => OutboundProviderEventType::Unknown,
        };

        try {
            $eventAt = isset($payload['occurred_at'])
                ? Carbon::parse((string) $payload['occurred_at'])
                : now();
        } catch (\Throwable) {
            throw new InvalidArgumentException('invalid_payload');
        }

        $metadata = [
            'reason_code' => isset($payload['reason_code'])
                ? mb_substr(preg_replace('/[^A-Za-z0-9._:-]/', '', (string) $payload['reason_code']) ?: '', 0, 64)
                : null,
        ];
        $metadata = array_filter($metadata, static fn ($value) => $value !== null && $value !== '');

        return new OutboundProviderEventData(
            provider: $provider,
            providerEventId: mb_substr($eventId, 0, 191),
            providerMessageId: $providerMessageId !== '' ? mb_substr($providerMessageId, 0, 255) : null,
            eventType: $eventType,
            providerEventAt: $eventAt,
            metadata: $metadata,
        );
    }
}
