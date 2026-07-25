<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Models\AuditLog;
use App\Models\OutboundMessage;
use Illuminate\Support\Facades\DB;

final class OutboundOpsService
{
    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $readiness = $this->readiness();
        $volume24h = $this->volume(now()->subDay());
        $volume7d = $this->volume(now()->subDays(7));
        $retries = $this->retryMetrics();
        $provider = $this->providerMetrics(now()->subDay());
        $issues = $this->issues($readiness, $retries, $volume24h);

        return [
            'status' => $this->overallStatus($readiness, $issues),
            'evaluated_at' => now()->toIso8601String(),
            'readiness' => $readiness,
            'volume' => [
                'last_24_hours' => $volume24h,
                'last_7_days' => $volume7d,
            ],
            'retries' => $retries,
            'provider' => $provider,
            'issues' => $issues,
            'thresholds' => [
                'oldest_queued_seconds' => (int) config('outbound.ops.oldest_queued_seconds_threshold', 600),
                'failed_last_hour' => (int) config('outbound.ops.failed_last_hour_threshold', 5),
                'temporary_failure_rate' => (int) config('outbound.ops.temporary_failure_rate_threshold', 10),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        if (! config('outbound.enabled', false)) {
            return [
                'transport' => (string) config('outbound.transport', 'unavailable'),
                'configuration_valid' => false,
                'state' => 'unknown',
                'failure_code' => 'outbound_disabled',
                'recent_sent_at' => null,
                'recent_failed_at' => null,
                'recent_failure_code' => null,
            ];
        }

        $transport = (string) config('outbound.transport', 'unavailable');
        $valid = in_array($transport, ['smtp', 'mail', 'array'], true);
        if ($transport === 'unavailable') {
            $valid = false;
        }

        $recentSent = OutboundMessage::query()
            ->where('state', OutboundMessageState::Sent->value)
            ->orderByDesc('sent_at')
            ->value('sent_at');
        $recentFailed = OutboundMessage::query()
            ->where('state', OutboundMessageState::Failed->value)
            ->orderByDesc('failed_at')
            ->first(['failed_at', 'failure_code']);

        $state = match (true) {
            ! $valid => 'failed',
            default => 'healthy',
        };

        return [
            'transport' => $transport,
            'configuration_valid' => $valid,
            'state' => $state,
            'failure_code' => $valid ? null : 'invalid_config',
            'recent_sent_at' => $recentSent?->toIso8601String(),
            'recent_failed_at' => $recentFailed?->failed_at?->toIso8601String(),
            'recent_failure_code' => $recentFailed?->failure_code,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function volume(\DateTimeInterface $since): array
    {
        $base = OutboundMessage::query()->where('created_at', '>=', $since);

        return [
            'queued' => (clone $base)->where('state', OutboundMessageState::Queued->value)->count(),
            'sending' => (clone $base)->where('state', OutboundMessageState::Sending->value)->count(),
            'sent' => (clone $base)->where('state', OutboundMessageState::Sent->value)->count(),
            'failed' => (clone $base)->where('state', OutboundMessageState::Failed->value)->count(),
            'cancelled' => (clone $base)->where('state', OutboundMessageState::Cancelled->value)->count(),
            'send_operations' => (clone $base)->where('operation', OutboundOperation::Send->value)->count(),
            'replies' => (clone $base)->where('operation', OutboundOperation::Reply->value)->count(),
            'forwards' => (clone $base)->where('operation', OutboundOperation::Forward->value)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retryMetrics(): array
    {
        $scheduled = AuditLog::query()
            ->where('action', 'outbound.retry_scheduled')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $exhausted = AuditLog::query()
            ->where('action', 'outbound.retry_exhausted')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $oldestQueued = OutboundMessage::query()
            ->where('state', OutboundMessageState::Queued->value)
            ->orderBy('queued_at')
            ->value('queued_at');

        $sending = OutboundMessage::query()
            ->where('state', OutboundMessageState::Sending->value)
            ->count();

        $failedJobs = 0;
        try {
            $failedJobs = (int) DB::table('failed_jobs')
                ->where('queue', (string) config('queue.workloads.outbound_delivery', 'outbound-delivery'))
                ->where('failed_at', '>=', now()->subDay())
                ->count();
        } catch (\Throwable) {
            $failedJobs = 0;
        }

        $permanent = OutboundMessage::query()
            ->where('state', OutboundMessageState::Failed->value)
            ->where('failed_at', '>=', now()->subDay())
            ->whereNotIn('failure_code', ['transport_temporary', 'smtp_4xx', 'timeout'])
            ->count();

        return [
            'retries_scheduled' => $scheduled,
            'retries_exhausted' => $exhausted,
            'oldest_queued_age_seconds' => $oldestQueued !== null ? max(0, now()->diffInSeconds($oldestQueued)) : 0,
            'currently_sending' => $sending,
            'failed_jobs' => $failedJobs,
            'permanent_failures' => $permanent,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function providerMetrics(\DateTimeInterface $since): array
    {
        return [
            'accepted' => OutboundMessage::query()->where('state', OutboundMessageState::Sent->value)->where('sent_at', '>=', $since)->count(),
            'temporary_rejections' => AuditLog::query()->where('action', 'outbound.retry_scheduled')->where('created_at', '>=', $since)->count(),
            'permanent_rejections' => OutboundMessage::query()->where('state', OutboundMessageState::Failed->value)->where('failed_at', '>=', $since)->count(),
            'rate_limits' => OutboundMessage::query()->where('failure_code', 'rate_limit')->where('failed_at', '>=', $since)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  array<string, mixed>  $retries
     * @param  array<string, int>  $volume24h
     * @return list<string>
     */
    private function issues(array $readiness, array $retries, array $volume24h): array
    {
        $issues = [];

        if (($readiness['state'] ?? '') === 'failed') {
            $issues[] = 'invalid_config';
        }

        if (($retries['oldest_queued_age_seconds'] ?? 0) > (int) config('outbound.ops.oldest_queued_seconds_threshold', 600)) {
            $issues[] = 'queue_backlog';
        }

        if (($volume24h['failed'] ?? 0) >= (int) config('outbound.ops.failed_last_hour_threshold', 5)
            && ($retries['retries_scheduled'] ?? 0) >= (int) config('outbound.ops.temporary_failure_rate_threshold', 10)) {
            $issues[] = 'elevated_temporary_failures';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  list<string>  $issues
     */
    private function overallStatus(array $readiness, array $issues): string
    {
        $state = (string) ($readiness['state'] ?? 'unknown');
        if ($state === 'unknown') {
            return 'unknown';
        }
        if ($state === 'failed' || in_array('invalid_config', $issues, true)) {
            return 'failed';
        }
        if ($issues !== []) {
            return 'degraded';
        }

        return 'healthy';
    }
}
