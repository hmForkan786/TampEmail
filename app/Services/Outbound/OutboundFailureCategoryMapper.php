<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\DTOs\Outbound\OutboundDeliveryResult;

/**
 * Buckets already-sanitized failure codes into small, stable safe
 * categories for delivery attempts and the message timeline.
 *
 * Input codes (`user_inactive`, `smtp_5xx`, `invalid_recipient`, ...) are
 * already sanitized by {@see OutboundDeliveryResult} and
 * the authorization/attachment layers; this mapper never sees raw SMTP
 * text, headers, or provider payloads. It only groups known-safe codes for
 * display, so admin and user surfaces stay stable even if a specific
 * internal failure code changes.
 */
final class OutboundFailureCategoryMapper
{
    private const AUTHORIZATION_CODES = [
        'user_inactive', 'authorization_failed', 'domain_outbound_disabled',
        'entitlement_denied', 'inbox_inactive', 'inbox_not_found',
    ];

    private const CONTENT_CODES = [
        'attachment_unsafe', 'attachment_not_found', 'attachment_deleted',
        'attachment_unavailable', 'email_not_found',
    ];

    private const SUPPRESSION_CODES = ['recipient_suppressed'];

    private const TEMPORARY_CODES = [
        'rate_limit', 'timeout', 'dns_failure', 'transport_temporary',
    ];

    private const PERMANENT_CODES = [
        'invalid_recipient', 'message_too_large', 'transport_rejected',
        'provider_bounce', 'provider_rejected', 'provider_permanent_failure',
        'transport_error',
    ];

    private const CONFIGURATION_CODES = [
        'credentials_rejected', 'tls_configuration', 'invalid_config',
    ];

    private const EXHAUSTED_CODES = ['stale_sending_attempts_exhausted'];

    /**
     * Detailed category for admin/operational surfaces.
     */
    public function categorize(?string $failureCode): string
    {
        if ($failureCode === null || $failureCode === '') {
            return 'unknown';
        }

        return match (true) {
            in_array($failureCode, self::AUTHORIZATION_CODES, true) => 'authorization',
            in_array($failureCode, self::CONTENT_CODES, true) => 'content',
            in_array($failureCode, self::SUPPRESSION_CODES, true) => 'suppression',
            in_array($failureCode, self::EXHAUSTED_CODES, true) => 'exhausted',
            in_array($failureCode, self::CONFIGURATION_CODES, true) => 'configuration',
            str_starts_with($failureCode, 'smtp_4') || in_array($failureCode, self::TEMPORARY_CODES, true) => 'transport_temporary',
            str_starts_with($failureCode, 'smtp_5') || in_array($failureCode, self::PERMANENT_CODES, true) => 'transport_permanent',
            default => 'unknown',
        };
    }

    /**
     * Coarser category safe for end-user display. Never distinguishes
     * transport internals (SMTP codes, provider names) that could leak
     * operational detail.
     */
    public function userSafeCategory(?string $failureCode): string
    {
        return $this->userSafeFromCategory($this->categorize($failureCode));
    }

    /**
     * Maps an already-computed admin category (e.g. the `failure_category`
     * stored on a delivery attempt row) to the coarser user-safe category,
     * without re-deriving it from a raw failure code.
     */
    public function userSafeFromCategory(?string $category): string
    {
        return match ($category) {
            'authorization' => 'authorization_issue',
            'content' => 'content_issue',
            'suppression' => 'recipient_issue',
            'transport_temporary' => 'temporary_issue',
            'transport_permanent', 'exhausted' => 'permanent_issue',
            'configuration' => 'system_issue',
            default => 'unknown',
        };
    }
}
