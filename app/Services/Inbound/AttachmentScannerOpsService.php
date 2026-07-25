<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\Enums\AttachmentScanStatus;
use App\Jobs\ScanInboundAttachmentJob;
use App\Models\Attachment;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Operational visibility for attachment scanning (no content access).
 */
final class AttachmentScannerOpsService
{
    public function __construct(
        private readonly AttachmentScannerHealthService $health,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $readiness = $this->readiness();
        $counts24h = $this->scanCounts(now()->subDay());
        $counts7d = $this->scanCounts(now()->subDays(7));
        $queue = $this->queueHealth();
        $quarantine = $this->quarantineOverview();
        $issues = $this->issues($readiness, $queue, $counts24h);

        return [
            'status' => $this->overallStatus($readiness, $issues),
            'evaluated_at' => now()->toIso8601String(),
            'readiness' => $readiness,
            'counts' => [
                'last_24_hours' => $counts24h,
                'last_7_days' => $counts7d,
            ],
            'queue' => $queue,
            'quarantine' => $quarantine,
            'issues' => $issues,
            'thresholds' => [
                'pending_backlog' => (int) config('attachments.ops.pending_backlog_threshold', 100),
                'oldest_pending_seconds' => (int) config('attachments.ops.oldest_pending_seconds_threshold', 600),
                'failed_scans_last_hour' => (int) config('attachments.ops.failed_scans_last_hour_threshold', 5),
                'retry_exhausted_surge' => (int) config('attachments.ops.retry_exhausted_surge_threshold', 10),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        try {
            $check = $this->health->check();
        } catch (\Throwable) {
            return [
                'backend' => 'unknown',
                'configuration_valid' => false,
                'daemon_reachable' => false,
                'protocol_ready' => false,
                'last_successful_health_check_at' => Cache::get('attachments.scanner.last_successful_check_at'),
                'last_failed_health_check_at' => Cache::get('attachments.scanner.last_failed_check_at'),
                'failure_code' => 'health_unavailable',
                'state' => 'failed',
            ];
        }

        $status = (string) ($check['status'] ?? 'failed');
        $state = match ($status) {
            'healthy' => 'healthy',
            'disabled' => 'unknown',
            'misconfigured' => 'failed',
            'unavailable' => 'degraded',
            default => 'failed',
        };

        if ($state !== 'healthy') {
            Cache::forever('attachments.scanner.last_failed_check_at', now()->toIso8601String());
        }

        return [
            'backend' => (string) ($check['backend'] ?? 'unknown'),
            'configuration_valid' => ! in_array($status, ['misconfigured', 'failed'], true),
            'daemon_reachable' => ($check['reachable'] ?? false) === true,
            'protocol_ready' => in_array((string) ($check['protocol'] ?? ''), ['pong', 'instream'], true) || $status === 'healthy',
            'last_successful_health_check_at' => $check['last_successful_check_at'] ?? Cache::get('attachments.scanner.last_successful_check_at'),
            'last_failed_health_check_at' => Cache::get('attachments.scanner.last_failed_check_at'),
            'failure_code' => $state === 'healthy' ? null : $this->safeCode((string) ($check['protocol'] ?? $status)),
            'state' => $state,
            'raw_status' => $status,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function scanCounts(\DateTimeInterface $since): array
    {
        $base = Attachment::query()->where('created_at', '>=', $since);

        return [
            'pending' => (clone $base)->where('scan_status', AttachmentScanStatus::Pending)->count(),
            'scanning' => (clone $base)->where('scan_status', AttachmentScanStatus::Scanning)->count(),
            'clean' => (clone $base)->where('scan_status', AttachmentScanStatus::Clean)->count(),
            'infected' => (clone $base)->where('scan_status', AttachmentScanStatus::Infected)->count(),
            'failed' => (clone $base)->where('scan_status', AttachmentScanStatus::Failed)->count(),
            'skipped' => (clone $base)->where('scan_status', AttachmentScanStatus::Skipped)->count(),
            'retry_scheduled' => AuditLog::query()
                ->where('action', 'attachment.scan_retry_scheduled')
                ->where('created_at', '>=', $since)
                ->count(),
            'retry_exhausted' => AuditLog::query()
                ->where('action', 'attachment.scan_retry_exhausted')
                ->where('created_at', '>=', $since)
                ->count(),
            'permanently_deleted' => AuditLog::query()
                ->where('action', 'attachment.quarantine_purged')
                ->where('created_at', '>=', $since)
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueHealth(): array
    {
        $queueName = (string) config('queue.workloads.attachment_scanning', 'attachment-scanning');
        $pendingJobs = $this->countJobs($queueName);
        $oldestAge = $this->oldestJobAge($queueName);
        $failedJobs = $this->countFailedScanJobs();
        $pendingAttachments = Attachment::query()->where('scan_status', AttachmentScanStatus::Pending)->count();
        $scanningAttachments = Attachment::query()->where('scan_status', AttachmentScanStatus::Scanning)->count();
        $oldestPendingAge = $this->oldestPendingAttachmentAge();

        return [
            'queue_name' => $queueName,
            'pending_scan_jobs' => $pendingJobs,
            'oldest_pending_scan_job_age_seconds' => $oldestAge,
            'failed_scan_jobs' => $failedJobs,
            'retry_backlog' => $pendingAttachments,
            'currently_processing' => $scanningAttachments,
            'oldest_pending_attachment_age_seconds' => $oldestPendingAge,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function quarantineOverview(): array
    {
        $infected = Attachment::query()->where('scan_status', AttachmentScanStatus::Infected)->count();
        $failed = Attachment::query()->where('scan_status', AttachmentScanStatus::Failed)->count();
        $oldest = Attachment::query()
            ->quarantined()
            ->orderBy('scanned_at')
            ->value('scanned_at');
        $recentPurges = AuditLog::query()
            ->where('action', 'attachment.quarantine_purged')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return [
            'infected_count' => $infected,
            'failed_count' => $failed,
            'awaiting_review' => $infected + $failed,
            'oldest_quarantined_age_seconds' => $oldest !== null ? max(0, (int) abs(now()->diffInSeconds($oldest))) : 0,
            'recent_permanent_deletions_24h' => $recentPurges,
        ];
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  array<string, mixed>  $queue
     * @param  array<string, int>  $counts24h
     * @return list<string>
     */
    private function issues(array $readiness, array $queue, array $counts24h): array
    {
        $issues = [];
        $state = (string) ($readiness['state'] ?? 'failed');

        if ($state === 'failed') {
            $issues[] = 'scanner_unreachable_or_invalid';
        } elseif ($state === 'degraded') {
            $issues[] = 'scanner_degraded';
        } elseif ($state === 'unknown') {
            $issues[] = 'scanner_disabled';
        }

        if (($queue['retry_backlog'] ?? 0) > (int) config('attachments.ops.pending_backlog_threshold', 100)) {
            $issues[] = 'pending_backlog_threshold';
        }
        if (($queue['oldest_pending_attachment_age_seconds'] ?? 0) > (int) config('attachments.ops.oldest_pending_seconds_threshold', 600)) {
            $issues[] = 'oldest_pending_threshold';
        }

        $failedLastHour = Attachment::query()
            ->where('scan_status', AttachmentScanStatus::Failed)
            ->where('scanned_at', '>=', now()->subHour())
            ->count();
        if ($failedLastHour > (int) config('attachments.ops.failed_scans_last_hour_threshold', 5)) {
            $issues[] = 'failed_scans_threshold';
        }

        if (($counts24h['retry_exhausted'] ?? 0) > (int) config('attachments.ops.retry_exhausted_surge_threshold', 10)) {
            $issues[] = 'retry_exhausted_surge';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  list<string>  $issues
     */
    private function overallStatus(array $readiness, array $issues): string
    {
        $state = (string) ($readiness['state'] ?? 'failed');
        if ($state === 'failed' || in_array('scanner_unreachable_or_invalid', $issues, true)) {
            return 'failed';
        }
        if ($issues !== [] || $state === 'degraded' || $state === 'unknown') {
            return 'degraded';
        }

        return 'healthy';
    }

    private function countJobs(string $queue): int
    {
        try {
            return (int) DB::table('jobs')->where('queue', $queue)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function oldestJobAge(string $queue): int
    {
        try {
            $job = DB::table('jobs')->where('queue', $queue)->orderBy('available_at')->first();

            return $job ? max(0, now()->timestamp - (int) $job->available_at) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countFailedScanJobs(): int
    {
        try {
            return (int) DB::table('failed_jobs')
                ->where('payload', 'like', '%'.class_basename(ScanInboundAttachmentJob::class).'%')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function oldestPendingAttachmentAge(): int
    {
        $created = Attachment::query()
            ->where('scan_status', AttachmentScanStatus::Pending)
            ->orderBy('created_at')
            ->value('created_at');

        return $created !== null ? max(0, (int) abs(now()->diffInSeconds($created))) : 0;
    }

    private function safeCode(string $code): string
    {
        $sanitized = preg_replace('/[^a-z0-9_:-]/i', '', $code) ?: 'unknown';

        return mb_substr(strtolower($sanitized), 0, 80);
    }
}
