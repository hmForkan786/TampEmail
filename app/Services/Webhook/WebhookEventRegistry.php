<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Exceptions\OutboundSendException;

/** Canonical user-webhook event catalogue and payload versioning. */
final class WebhookEventRegistry
{
    public const PAYLOAD_VERSION = '2026-07-27';

    /** @var list<string> */
    private const SUPPORTED = [
        'inbox.email.received',
        'outbound.message.sent',
        'outbound.message.delivered',
        'outbound.message.failed',
        'outbound.message.bounced',
    ];

    /** @return list<string> */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }

    public static function supports(string $eventType): bool
    {
        return in_array($eventType, self::SUPPORTED, true);
    }

    /** @param  list<string>  $eventTypes */
    public static function assertSupported(array $eventTypes): void
    {
        foreach ($eventTypes as $eventType) {
            if (! self::supports($eventType)) {
                throw new OutboundSendException(
                    'webhook_event_unsupported',
                    "Unsupported webhook event type [{$eventType}].",
                    422,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitizePayload(string $eventType, string $eventId, array $data): array
    {
        return [
            'schema_version' => self::PAYLOAD_VERSION,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => now()->toIso8601String(),
            'data' => self::sanitizeData($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function sanitizeData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (str_contains(strtolower($key), 'secret')) {
                continue;
            }
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeData($value);

                continue;
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
