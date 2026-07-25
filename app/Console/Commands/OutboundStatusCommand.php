<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Outbound\OutboundOpsService;
use Illuminate\Console\Command;

final class OutboundStatusCommand extends Command
{
    protected $signature = 'outbound:status {--json : Print a JSON summary}';

    protected $description = 'Aggregate outbound email readiness, volume, retry, and provider metrics.';

    public function handle(OutboundOpsService $ops): int
    {
        try {
            $summary = $ops->report();
        } catch (\Throwable) {
            $summary = [
                'status' => 'failed',
                'evaluated_at' => now()->toIso8601String(),
                'readiness' => ['state' => 'failed', 'failure_code' => 'ops_unavailable'],
                'volume' => ['last_24_hours' => [], 'last_7_days' => []],
                'retries' => [],
                'provider' => [],
                'issues' => ['ops_unavailable'],
            ];
        }

        $safe = $this->safeSummary($summary);

        if ($this->option('json')) {
            $this->line(json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.($safe['status'] ?? 'failed'));
            $this->line('readiness: '.($safe['readiness']['state'] ?? 'unknown'));
            $this->line('queued_age_seconds: '.($safe['retries']['oldest_queued_age_seconds'] ?? 0));
            $this->line('sent_24h: '.($safe['volume']['last_24_hours']['sent'] ?? 0));
            $this->line('failed_24h: '.($safe['volume']['last_24_hours']['failed'] ?? 0));
        }

        return match ($safe['status'] ?? 'failed') {
            'healthy' => self::SUCCESS,
            'degraded', 'unknown' => 2,
            default => self::FAILURE,
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function safeSummary(array $summary): array
    {
        $status = $summary['status'] ?? 'failed';
        if (! in_array($status, ['healthy', 'degraded', 'failed', 'unknown'], true)) {
            $status = 'failed';
        }

        return [
            'status' => $status,
            'evaluated_at' => is_string($summary['evaluated_at'] ?? null) ? $summary['evaluated_at'] : now()->toIso8601String(),
            'readiness' => [
                'transport' => (string) (($summary['readiness']['transport'] ?? 'unavailable')),
                'configuration_valid' => (bool) ($summary['readiness']['configuration_valid'] ?? false),
                'state' => (string) ($summary['readiness']['state'] ?? 'failed'),
                'failure_code' => isset($summary['readiness']['failure_code']) ? (string) $summary['readiness']['failure_code'] : null,
                'recent_sent_at' => $summary['readiness']['recent_sent_at'] ?? null,
                'recent_failed_at' => $summary['readiness']['recent_failed_at'] ?? null,
                'recent_failure_code' => $summary['readiness']['recent_failure_code'] ?? null,
            ],
            'volume' => $summary['volume'] ?? ['last_24_hours' => [], 'last_7_days' => []],
            'retries' => $summary['retries'] ?? [],
            'provider' => $summary['provider'] ?? [],
            'issues' => array_values(array_filter(
                is_array($summary['issues'] ?? null) ? $summary['issues'] : [],
                fn ($issue): bool => is_string($issue) && preg_match('/^[a-z0-9_]{1,80}$/', $issue) === 1,
            )),
            'thresholds' => $summary['thresholds'] ?? [],
        ];
    }
}
