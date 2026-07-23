<?php
declare(strict_types=1);
namespace App\Services\Ops;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
final class ProcessReadinessService
{
    public function __construct(
        private readonly ProcessHeartbeatWriter $heartbeats,
    ) {}

    public function report(): array
    {
        $ttl = max(1, (int) config('processes.heartbeat_ttl_seconds', 180));
        $now = now();
        $workerRecords = $this->heartbeats->workerRecords();
        $schedulerRecord = $this->heartbeats->currentSchedulerRecord();
        $failed = $this->count('failed_jobs');
        $backlog = $this->count('jobs');
        $oldest = $this->oldestJobAge();
        $issues = [];
        $queueConnection = (string) config('queue.default');
        $cacheStore = (string) config('cache.default');
        $expectedWorkerCount = max(0, (int) config('processes.worker_count', 1));
        $freshWorkerRecords = array_values(array_filter($workerRecords, fn (array $record): bool => ($record['status'] ?? null) !== 'stopped' && $this->hasFreshHeartbeat($record['last_heartbeat_at'] ?? null, $now, $ttl)));
        $freshWorkerCount = count($freshWorkerRecords);
        $schedulerFresh = $this->hasFreshHeartbeat($schedulerRecord['last_success_at'] ?? $schedulerRecord['last_heartbeat_at'] ?? null, $now, $ttl);

        if ($expectedWorkerCount > 0 && $freshWorkerCount === 0) $issues[] = 'worker_heartbeat_stale';
        if ($expectedWorkerCount > 0 && $freshWorkerCount < $expectedWorkerCount) $issues[] = 'worker_heartbeat_count_mismatch';
        if (! $schedulerFresh) $issues[] = 'scheduler_heartbeat_stale';
        if ($queueConnection === 'sync') $issues[] = 'queue_connection_sync';
        if (! in_array($cacheStore, ['redis', 'database'], true)) $issues[] = 'lock_store_incompatible';
        if ($failed > (int) config('processes.failed_jobs_threshold', 10)) $issues[] = 'failed_jobs_threshold';
        if ($backlog > (int) config('processes.backlog_threshold', 100)) $issues[] = 'queue_backlog_threshold';
        if ($oldest > (int) config('processes.oldest_job_age_seconds', 900)) $issues[] = 'oldest_job_threshold';

        $hasFailures = $failed < 0 || $backlog < 0 || $oldest < 0;

        return ['status' => $hasFailures ? 'failed' : ($issues === [] ? 'healthy' : 'degraded'), 'issues' => $issues, 'queue' => ['connection' => $queueConnection, 'workloads' => config('queue.workloads'), 'worker_count' => config('processes.worker_count'), 'timeout' => config('processes.timeout'), 'sleep' => config('processes.sleep'), 'tries' => config('processes.tries'), 'max_jobs' => config('processes.max_jobs'), 'max_time' => config('processes.max_time'), 'memory' => config('processes.memory'), 'failed_jobs' => $failed, 'backlog' => $backlog, 'oldest_job_age_seconds' => $oldest], 'worker' => ['expected_count' => $expectedWorkerCount, 'fresh_count' => $freshWorkerCount, 'records' => array_map(fn (array $record): array => $this->safeHeartbeatRecord($record), $workerRecords)], 'scheduler' => ['fresh' => $schedulerFresh, 'record' => $schedulerRecord === null ? null : $this->safeHeartbeatRecord($schedulerRecord)], 'lock_store' => ['cache' => $cacheStore, 'compatible' => in_array($cacheStore, ['redis', 'database'], true)]];
    }

    private function hasFreshHeartbeat(mixed $heartbeat, CarbonInterface $now, int $ttl): bool
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

    /** @return array<string, mixed> */
    private function safeHeartbeatRecord(array $record): array
    {
        unset($record['process_id'], $record['instance_id']);

        return $record;
    }

    private function count(string $table): int { try { return (int) DB::table($table)->count(); } catch (\Throwable) { return -1; } }
    private function oldestJobAge(): int { try { $job = DB::table('jobs')->orderBy('available_at')->first(); return $job ? max(0, now()->timestamp - (int) $job->available_at) : 0; } catch (\Throwable) { return -1; } }
}
