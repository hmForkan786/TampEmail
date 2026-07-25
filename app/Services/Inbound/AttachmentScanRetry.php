<?php

declare(strict_types=1);

namespace App\Services\Inbound;

/**
 * Bounded, fail-closed attachment scan retry configuration.
 */
final class AttachmentScanRetry
{
    /** @var list<string> */
    public const RETRYABLE_CODES = [
        'clamav:unavailable',
        'clamav:timeout',
        'clamav:write',
        'clamav:error',
    ];

    /** @var list<int> */
    private const DEFAULT_BACKOFF = [60, 300, 900];

    public static function maxAttempts(): int
    {
        $configured = (int) config('attachments.retry.max_attempts', 3);

        return max(1, min(10, $configured > 0 ? $configured : 3));
    }

    /**
     * @return list<int>
     */
    public static function backoffSeconds(): array
    {
        $configured = config('attachments.retry.backoff_seconds', self::DEFAULT_BACKOFF);

        if (is_string($configured)) {
            $configured = array_map('trim', explode(',', $configured));
        }

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_BACKOFF;
        }

        $seconds = [];
        foreach ($configured as $value) {
            if (! is_numeric($value)) {
                return self::DEFAULT_BACKOFF;
            }
            $int = (int) $value;
            if ($int < 1 || $int > 86400) {
                return self::DEFAULT_BACKOFF;
            }
            $seconds[] = $int;
        }

        return $seconds !== [] ? array_values($seconds) : self::DEFAULT_BACKOFF;
    }

    public static function isRetryableCode(?string $code): bool
    {
        return is_string($code) && in_array($code, self::RETRYABLE_CODES, true);
    }

    public static function jobTimeoutSeconds(): int
    {
        $scannerTimeout = (int) config('attachments.clamav.timeout_seconds', 30);

        return max(30, min(300, $scannerTimeout + 90));
    }
}
