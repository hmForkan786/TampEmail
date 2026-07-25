<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Services\Ops\ProcessHeartbeatWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Outbound-specific worker/queue readiness: explicit queue topology,
 * timeout alignment, and per-queue worker heartbeat/backlog/failed-job
 * visibility for the delivery, provider-event, and maintenance workloads.
 *
 * Complements the generic App\Services\Ops\ProcessReadinessService, which
 * reports aggregate (not per-queue) worker/scheduler health.
 */
final class OutboundQueueReadinessService
{
    public function __construct(
        private readonly ProcessHeartbeatWriter $heartbeats,
        private readonly OutboundWorkerConfigValidator $workerConfig,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $topology = $this->topology();
        $workerValidation = $this->workerConfig->validate();

        $ttl = max(1, (int) config('processes.heartbeat_ttl_seconds', 180));
        $now = now();
        $workerRecords = $this->heartbeats->workerRecords();
        $schedulerRecord = $this->heartbeats->currentSchedulerRecord();
        $schedulerFresh = $this->hasFreshHeartbeat(
            $schedulerRecord['last_success_at'] ?? $schedulerRecord['last_heartbeat_at'] ?? null,
            $now,
            $ttl,
        );

        $delivery = $this->queueMetrics(
            $topology['outbound_delivery'],
            $workerRecords,
            $now,
            $ttl,
            (int) config('outbound.worker.delivery_count', 1),
        );
        $events = $this->queueMetrics(
            $topology['outbound_events'],
            $workerRecords,
            $now,
            $ttl,
            (int) config('outbound.worker.events_count', 1),
        );

        $issues = [];
        if (! $topology['valid']) {
            $issues[] = 'invalid_queue_topology';
        }
        if (! $workerValidation['valid']) {
            $issues[] = 'invalid_worker_timeout_config';
        }
        if (! $schedulerFresh) {
            $issues[] = 'maintenance_scheduler_heartbeat_stale';
        }

        foreach (['delivery' => $delivery, 'events' => $events] as $label => $queue) {
            if ($queue['expected_workers'] > 0 && $queue['fresh_workers'] === 0) {
                $issues[] = $label.'_worker_heartbeat_missing';
            }
            if ($queue['backlog'] > (int) config('processes.backlog_threshold', 100)) {
                $issues[] = $label.'_backlog_threshold';
            }
            if ($queue['failed_jobs'] > (int) config('processes.failed_jobs_threshold', 10)) {
                $issues[] = $label.'_failed_jobs_threshold';
            }
            if ($queue['oldest_job_age_seconds'] > (int) config('processes.oldest_job_age_seconds', 900)) {
                $issues[] = $label.'_oldest_job_threshold';
            }
        }

        $hasDataError = $delivery['backlog'] < 0 || $events['backlog'] < 0
            || $delivery['failed_jobs'] < 0 || $events['failed_jobs'] < 0
            || $delivery['oldest_job_age_seconds'] < 0 || $events['oldest_job_age_seconds'] < 0;

        $status = match (true) {
            ! $topology['valid'], ! $workerValidation['valid'], $hasDataError => 'failed',
            $issues !== [] => 'degraded',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'issues' => $issues,
            'topology' => $topology,
            'worker_config' => $workerValidation,
            'delivery' => $delivery,
            'events' => $events,
            'maintenance' => [
                'queue' => $topology['outbound_maintenance'],
                'scheduler_fresh' => $schedulerFresh,
            ],
        ];
    }

    /**
     * @return array{valid: bool, outbound_delivery: string, outbound_events: string, outbound_maintenance: string, attachment_scanning: string}
     */
    private function topology(): array
    {
        $workloads = (array) config('queue.workloads', []);
        $delivery = (string) ($workloads['outbound_delivery'] ?? 'outbound-delivery');
        $events = (string) ($workloads['outbound_events'] ?? 'outbound-events');
        $maintenance = (string) ($workloads['outbound_maintenance'] ?? 'outbound-maintenance');
        $attachmentScanning = (string) ($workloads['attachment_scanning'] ?? 'attachment-scanning');

        $names = [$delivery, $events, $maintenance];
        $valid = $delivery !== '' && $events !== '' && $maintenance !== ''
            && count(array_unique($names)) === 3
            && ! in_array($attachmentScanning, $names, true);

        return [
            'valid' => $valid,
            'outbound_delivery' => $delivery,
            'outbound_events' => $events,
            'outbound_maintenance' => $maintenance,
            'attachment_scanning' => $attachmentScanning,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $workerRecords
     * @return array<string, mixed>
     */
    private function queueMetrics(string $queueName, array $workerRecords, Carbon $now, int $ttl, int $expectedWorkers): array
    {
        $freshWorkers = 0;
        foreach ($workerRecords as $record) {
            if (($record['status'] ?? null) === 'stopped') {
                continue;
            }
            $queueNames = is_array($record['queue_names'] ?? null) ? $record['queue_names'] : [];
            if (! in_array($queueName, $queueNames, true)) {
                continue;
            }
            if ($this->hasFreshHeartbeat($record['last_heartbeat_at'] ?? null, $now, $ttl)) {
                $freshWorkers++;
            }
        }

        return [
            'queue' => $queueName,
            'expected_workers' => max(0, $expectedWorkers),
            'fresh_workers' => $freshWorkers,
            'backlog' => $this->count($queueName, 'jobs'),
            'failed_jobs' => $this->count($queueName, 'failed_jobs'),
            'oldest_job_age_seconds' => $this->oldestJobAge($queueName),
        ];
    }

    private function count(string $queueName, string $table): int
    {
        try {
            return (int) DB::table($table)->where('queue', $queueName)->count();
        } catch (\Throwable) {
            return -1;
        }
    }

    private function oldestJobAge(string $queueName): int
    {
        try {
            $job = DB::table('jobs')->where('queue', $queueName)->orderBy('available_at')->first();

            return $job !== null ? max(0, now()->timestamp - (int) $job->available_at) : 0;
        } catch (\Throwable) {
            return -1;
        }
    }

    private function hasFreshHeartbeat(mixed $heartbeat, Carbon $now, int $ttl): bool
    {
        if ($heartbeat === null || $heartbeat === '') {
            return false;
        }

        try {
            return abs($now->diffInSeconds(Carbon::parse((string) $heartbeat))) <= $ttl;
        } catch (\Throwable) {
            return false;
        }
    }
}
