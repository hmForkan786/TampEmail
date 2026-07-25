<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Inbound\AttachmentScannerOpsService;
use Illuminate\Console\Command;

final class AttachmentScannerStatus extends Command
{
    protected $signature = 'attachments:scanner-status {--json : Print a JSON summary}';

    protected $description = 'Aggregate attachment scanner readiness, scan counts, queue health, and quarantine overview.';

    public function handle(AttachmentScannerOpsService $ops): int
    {
        try {
            $summary = $ops->report();
        } catch (\Throwable) {
            $summary = [
                'status' => 'failed',
                'evaluated_at' => now()->toIso8601String(),
                'readiness' => ['state' => 'failed', 'failure_code' => 'health_unavailable'],
                'counts' => ['last_24_hours' => [], 'last_7_days' => []],
                'queue' => [],
                'quarantine' => [],
                'issues' => ['health_unavailable'],
            ];
        }

        $safe = $this->safeSummary($summary);

        if ($this->option('json')) {
            $this->line(json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.($safe['status'] ?? 'failed'));
            $this->line('readiness: '.($safe['readiness']['state'] ?? 'unknown'));
            $this->line('pending_backlog: '.($safe['queue']['retry_backlog'] ?? 'unknown'));
            $this->line('quarantine_awaiting_review: '.($safe['quarantine']['awaiting_review'] ?? 'unknown'));
        }

        return match ($safe['status'] ?? 'failed') {
            'healthy' => self::SUCCESS,
            'degraded' => 2,
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
        if (! in_array($status, ['healthy', 'degraded', 'failed'], true)) {
            $status = 'failed';
        }

        $readiness = is_array($summary['readiness'] ?? null) ? $summary['readiness'] : [];
        $queue = is_array($summary['queue'] ?? null) ? $summary['queue'] : [];
        $quarantine = is_array($summary['quarantine'] ?? null) ? $summary['quarantine'] : [];
        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];

        return [
            'status' => $status,
            'evaluated_at' => is_string($summary['evaluated_at'] ?? null) ? $summary['evaluated_at'] : now()->toIso8601String(),
            'readiness' => [
                'backend' => $this->text($readiness['backend'] ?? 'unknown'),
                'state' => $this->text($readiness['state'] ?? 'unknown'),
                'configuration_valid' => ($readiness['configuration_valid'] ?? false) === true,
                'daemon_reachable' => ($readiness['daemon_reachable'] ?? false) === true,
                'protocol_ready' => ($readiness['protocol_ready'] ?? false) === true,
                'failure_code' => $this->text($readiness['failure_code'] ?? 'none'),
                'last_successful_health_check_at' => $readiness['last_successful_health_check_at'] ?? null,
                'last_failed_health_check_at' => $readiness['last_failed_health_check_at'] ?? null,
            ],
            'counts' => [
                'last_24_hours' => $this->counts($counts['last_24_hours'] ?? []),
                'last_7_days' => $this->counts($counts['last_7_days'] ?? []),
            ],
            'queue' => [
                'queue_name' => $this->text($queue['queue_name'] ?? 'unknown'),
                'pending_scan_jobs' => $this->number($queue['pending_scan_jobs'] ?? 0),
                'oldest_pending_scan_job_age_seconds' => $this->number($queue['oldest_pending_scan_job_age_seconds'] ?? 0),
                'failed_scan_jobs' => $this->number($queue['failed_scan_jobs'] ?? 0),
                'retry_backlog' => $this->number($queue['retry_backlog'] ?? 0),
                'currently_processing' => $this->number($queue['currently_processing'] ?? 0),
                'oldest_pending_attachment_age_seconds' => $this->number($queue['oldest_pending_attachment_age_seconds'] ?? 0),
            ],
            'quarantine' => [
                'infected_count' => $this->number($quarantine['infected_count'] ?? 0),
                'failed_count' => $this->number($quarantine['failed_count'] ?? 0),
                'awaiting_review' => $this->number($quarantine['awaiting_review'] ?? 0),
                'oldest_quarantined_age_seconds' => $this->number($quarantine['oldest_quarantined_age_seconds'] ?? 0),
                'recent_permanent_deletions_24h' => $this->number($quarantine['recent_permanent_deletions_24h'] ?? 0),
            ],
            'issues' => array_values(array_filter(
                is_array($summary['issues'] ?? null) ? $summary['issues'] : [],
                fn ($issue): bool => is_string($issue) && preg_match('/^[a-z0-9_]{1,80}$/', $issue) === 1,
            )),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function counts(mixed $counts): array
    {
        $counts = is_array($counts) ? $counts : [];

        return [
            'pending' => $this->number($counts['pending'] ?? 0),
            'scanning' => $this->number($counts['scanning'] ?? 0),
            'clean' => $this->number($counts['clean'] ?? 0),
            'infected' => $this->number($counts['infected'] ?? 0),
            'failed' => $this->number($counts['failed'] ?? 0),
            'skipped' => $this->number($counts['skipped'] ?? 0),
            'retry_scheduled' => $this->number($counts['retry_scheduled'] ?? 0),
            'retry_exhausted' => $this->number($counts['retry_exhausted'] ?? 0),
            'permanently_deleted' => $this->number($counts['permanently_deleted'] ?? 0),
        ];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, 80) : 'unknown';
    }

    private function number(mixed $value): int
    {
        return is_numeric($value) ? max(0, min((int) $value, 2147483647)) : 0;
    }
}
