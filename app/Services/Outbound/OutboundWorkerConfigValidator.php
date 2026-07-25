<?php

declare(strict_types=1);

namespace App\Services\Outbound;

/**
 * Validates the required timeout ordering for outbound delivery workers
 * without sending mail or exposing secrets: transport timeout < job timeout
 * < queue connection retry_after, with a minimum retry_after floor.
 */
final class OutboundWorkerConfigValidator
{
    private const MINIMUM_RETRY_AFTER_SECONDS = 90;

    /**
     * @return array{
     *     valid: bool,
     *     failure_code: string|null,
     *     checks: array<string, int|string|bool|null>
     * }
     */
    public function validate(): array
    {
        $connection = (string) config('queue.default', 'database');
        $smtpTimeout = (int) config('outbound.smtp.timeout', 30);
        $jobTimeout = (int) config('outbound.worker.job_timeout_seconds', 60);
        $retryAfterRaw = config("queue.connections.{$connection}.retry_after");
        $retryAfter = is_numeric($retryAfterRaw) ? (int) $retryAfterRaw : null;

        $checks = [
            'connection' => $connection,
            'smtp_timeout_seconds' => $smtpTimeout,
            'job_timeout_seconds' => $jobTimeout,
            'retry_after_seconds' => $retryAfter,
            'smtp_below_job_timeout' => $smtpTimeout > 0 && $smtpTimeout < $jobTimeout,
            'job_timeout_below_retry_after' => $retryAfter !== null && $jobTimeout > 0 && $jobTimeout < $retryAfter,
            'retry_after_minimum' => $retryAfter !== null && $retryAfter >= self::MINIMUM_RETRY_AFTER_SECONDS,
        ];

        if ($retryAfter === null) {
            return $this->result(false, 'retry_after_unavailable', $checks);
        }

        if (! $checks['smtp_below_job_timeout']) {
            return $this->result(false, 'smtp_timeout_not_below_job_timeout', $checks);
        }

        if (! $checks['job_timeout_below_retry_after']) {
            return $this->result(false, 'job_timeout_not_below_retry_after', $checks);
        }

        if (! $checks['retry_after_minimum']) {
            return $this->result(false, 'retry_after_below_minimum', $checks);
        }

        return $this->result(true, null, $checks);
    }

    /**
     * @param  array<string, int|string|bool|null>  $checks
     * @return array{valid: bool, failure_code: string|null, checks: array<string, int|string|bool|null>}
     */
    private function result(bool $valid, ?string $failureCode, array $checks): array
    {
        return [
            'valid' => $valid,
            'failure_code' => $failureCode,
            'checks' => $checks,
        ];
    }
}
