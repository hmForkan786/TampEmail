<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundProviderEventParserInterface;
use App\DTOs\Outbound\OutboundProviderEventData;
use App\Enums\OutboundProviderEventType;
use App\Exceptions\OutboundSnsSubscriptionConfirmationException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Amazon SES outbound provider adapter via SNS HTTP(S) notifications.
 *
 * Verifies SNS signatures (certificate-based), normalizes SES event types,
 * and extracts Message-ID correlation fields. Subscription confirmations are
 * never auto-confirmed — they are surfaced for an administrative command.
 */
final class SesOutboundProviderEventParser implements OutboundProviderEventParserInterface
{
    public function __construct(
        private readonly SesSnsSignatureVerifier $signatures,
    ) {}

    public function supports(string $provider): bool
    {
        $providers = config('outbound.delivery_webhook.providers', []);

        return $provider === 'ses' && is_array($providers) && array_key_exists('ses', $providers);
    }

    public function verifySignature(Request $request, string $provider, string $rawBody): bool
    {
        $envelope = $this->decodeEnvelope($rawBody);
        if ($envelope === null) {
            return false;
        }

        $topicAllowlist = trim((string) (config('outbound.delivery_webhook.providers.ses.topic_arn') ?? ''));
        $topicArn = trim((string) ($envelope['TopicArn'] ?? ''));
        if ($topicAllowlist !== '' && ! hash_equals($topicAllowlist, $topicArn)) {
            return false;
        }

        return $this->signatures->verify($envelope);
    }

    public function replayFingerprint(Request $request, string $provider, string $rawBody): string
    {
        $envelope = $this->decodeEnvelope($rawBody) ?? [];
        $messageId = trim((string) ($envelope['MessageId'] ?? ''));
        $signature = trim((string) ($envelope['Signature'] ?? ''));

        if ($messageId === '' || $signature === '') {
            return hash('sha256', $provider.'|'.$rawBody);
        }

        return hash('sha256', $provider.'|'.$messageId.'|'.$signature);
    }

    public function parse(Request $request, string $provider, string $rawBody): OutboundProviderEventData
    {
        $envelope = $this->decodeEnvelope($rawBody);
        if ($envelope === null) {
            throw new InvalidArgumentException('invalid_payload');
        }

        $type = trim((string) ($envelope['Type'] ?? ''));
        $snsMessageId = trim((string) ($envelope['MessageId'] ?? ''));
        if ($snsMessageId === '') {
            throw new InvalidArgumentException('invalid_payload');
        }

        if ($type === 'SubscriptionConfirmation') {
            $this->handleSubscriptionConfirmation($envelope, $snsMessageId);
        }

        if ($type === 'UnsubscribeConfirmation') {
            return new OutboundProviderEventData(
                provider: $provider,
                providerEventId: mb_substr('sns-unsub-'.$snsMessageId, 0, 191),
                providerMessageId: null,
                eventType: OutboundProviderEventType::Unknown,
                providerEventAt: $this->parseTimestamp((string) ($envelope['Timestamp'] ?? '')),
                metadata: ['sns_type' => 'UnsubscribeConfirmation'],
            );
        }

        if ($type !== 'Notification') {
            throw new InvalidArgumentException('invalid_payload');
        }

        $innerRaw = (string) ($envelope['Message'] ?? '');
        $inner = json_decode($innerRaw, true);
        if (! is_array($inner)) {
            throw new InvalidArgumentException('invalid_payload');
        }

        [$eventType, $reasonCode] = $this->mapEventType($inner);
        $providerMessageId = $this->extractProviderMessageId($inner);
        $eventAt = $this->extractEventTimestamp($inner, (string) ($envelope['Timestamp'] ?? ''));

        $metadata = array_filter([
            'reason_code' => $reasonCode,
            'sns_type' => 'Notification',
            'ses_notification' => $this->safeNotificationLabel($inner),
        ], static fn ($value) => $value !== null && $value !== '');

        return new OutboundProviderEventData(
            provider: $provider,
            providerEventId: mb_substr($snsMessageId, 0, 191),
            providerMessageId: $providerMessageId,
            eventType: $eventType,
            providerEventAt: $eventAt,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeEnvelope(string $rawBody): ?array
    {
        $payload = json_decode($rawBody, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function handleSubscriptionConfirmation(array $envelope, string $snsMessageId): never
    {
        $subscribeUrl = trim((string) ($envelope['SubscribeURL'] ?? $envelope['SubscribeUrl'] ?? ''));
        $topicArn = trim((string) ($envelope['TopicArn'] ?? ''));
        $token = trim((string) ($envelope['Token'] ?? ''));

        if ($subscribeUrl === '' || $topicArn === '' || $token === '') {
            throw new InvalidArgumentException('invalid_payload');
        }

        if (! $this->isSafeSubscribeUrl($subscribeUrl)) {
            throw new InvalidArgumentException('invalid_subscribe_url');
        }

        $ttl = max(300, (int) config('outbound.delivery_webhook.providers.ses.subscription_cache_ttl_seconds', 3600));
        Cache::put('outbound.ses.pending_subscription', [
            'subscribe_url' => $subscribeUrl,
            'topic_arn' => $topicArn,
            'token_hash' => hash('sha256', $token),
            'message_id' => $snsMessageId,
            'received_at' => now()->toIso8601String(),
        ], $ttl);

        throw new OutboundSnsSubscriptionConfirmationException(
            subscribeUrl: $subscribeUrl,
            topicArn: $topicArn,
            token: $token,
            messageId: $snsMessageId,
        );
    }

    public function isSafeSubscribeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // SNS confirmations are hosted on sns.*.amazonaws.com only.
        if (preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/i', $host) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $inner
     * @return array{0: OutboundProviderEventType, 1: string|null}
     */
    private function mapEventType(array $inner): array
    {
        $label = strtolower(trim((string) (
            $inner['eventType']
            ?? $inner['notificationType']
            ?? $inner['event_type']
            ?? ''
        )));

        return match ($label) {
            'send' => [OutboundProviderEventType::Accepted, null],
            'delivery' => [OutboundProviderEventType::Delivered, null],
            'deliverydelay', 'delivery_delay' => [OutboundProviderEventType::TemporaryFailure, 'delivery_delay'],
            'bounce' => $this->mapBounce($inner),
            'complaint' => [OutboundProviderEventType::Complained, 'complaint'],
            'reject' => [OutboundProviderEventType::Rejected, $this->safeReason((string) (
                data_get($inner, 'reject.reason')
                ?? data_get($inner, 'rejection.reason')
                ?? 'rejected'
            ))],
            'rendering failure', 'renderingfailure' => [OutboundProviderEventType::Rejected, 'rendering_failure'],
            default => [OutboundProviderEventType::Unknown, $label !== '' ? $this->safeReason($label) : null],
        };
    }

    /**
     * @param  array<string, mixed>  $inner
     * @return array{0: OutboundProviderEventType, 1: string|null}
     */
    private function mapBounce(array $inner): array
    {
        $type = strtolower(trim((string) (data_get($inner, 'bounce.bounceType') ?? '')));

        if ($type === 'transient') {
            return [OutboundProviderEventType::TemporaryFailure, 'transient_bounce'];
        }

        return [OutboundProviderEventType::Bounced, $this->safeReason($type !== '' ? $type : 'permanent')];
    }

    /**
     * @param  array<string, mixed>  $inner
     */
    private function extractProviderMessageId(array $inner): ?string
    {
        $candidates = [
            data_get($inner, 'mail.commonHeaders.messageId'),
            data_get($inner, 'mail.commonHeaders.message-id'),
            data_get($inner, 'mail.headers.message-id'),
            data_get($inner, 'mail.messageId'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $normalized = $this->normalizeMessageId($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeMessageId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! str_starts_with($value, '<')) {
            $value = '<'.$value;
        }
        if (! str_ends_with($value, '>')) {
            $value .= '>';
        }

        $value = mb_substr($value, 0, 255);
        if (preg_match('/^<[^<>\s]+>$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $inner
     */
    private function extractEventTimestamp(array $inner, string $snsTimestamp): Carbon
    {
        $candidates = [
            data_get($inner, 'delivery.timestamp'),
            data_get($inner, 'bounce.timestamp'),
            data_get($inner, 'complaint.timestamp'),
            data_get($inner, 'mail.timestamp'),
            $snsTimestamp,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            try {
                return Carbon::parse($candidate);
            } catch (\Throwable) {
                continue;
            }
        }

        return now();
    }

    private function parseTimestamp(string $timestamp): Carbon
    {
        try {
            return $timestamp !== '' ? Carbon::parse($timestamp) : now();
        } catch (\Throwable) {
            return now();
        }
    }

    /**
     * @param  array<string, mixed>  $inner
     */
    private function safeNotificationLabel(array $inner): ?string
    {
        $label = strtolower(trim((string) (
            $inner['eventType'] ?? $inner['notificationType'] ?? ''
        )));

        return $label !== '' ? $this->safeReason($label) : null;
    }

    private function safeReason(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._:-]/', '', $value) ?: '';

        return mb_substr($clean, 0, 64);
    }
}
