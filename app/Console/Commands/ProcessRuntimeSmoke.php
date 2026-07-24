<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\ProcessReadinessService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class ProcessRuntimeSmoke extends Command
{
    protected $signature = 'processes:runtime-smoke {--json}';
    protected $description = 'Verify queue/cache topology and safe process heartbeat evidence.';

    public function handle(ProcessReadinessService $readiness, CacheFactory $cache): int
    {
        try {
            $queue = (string) config('queue.default');
            $cacheStore = (string) config('cache.default');
            $supportedQueue = in_array($queue, ['redis', 'database'], true);
            $supportedCache = in_array($cacheStore, ['redis', 'database'], true);
            $checks = ['queue' => $supportedQueue ? 'configured' : 'unsupported', 'cache' => $supportedCache ? 'configured' : 'unsupported'];

            if ($queue === 'redis') {
                $connection = (string) config('queue.connections.redis.connection', 'default');
                Redis::connection($connection)->ping();
                $checks['queue'] = 'reachable';
            }
            if ($cacheStore === 'redis') {
                $cache->store($cacheStore)->get('__process_runtime_smoke_probe__');
                $checks['cache'] = 'reachable';
            }

            $report = $readiness->report();
            $status = $report['status'] ?? 'failed';
            if (! $supportedQueue || ! $supportedCache) $status = 'degraded';
            $payload = ['status' => $status, 'checks' => $checks, 'process' => [
                'worker_fresh_count' => $report['worker']['fresh_count'] ?? 0,
                'worker_expected_count' => $report['worker']['expected_count'] ?? 0,
                'scheduler_fresh' => (bool) ($report['scheduler']['fresh'] ?? false),
            ], 'issues' => $report['issues'] ?? []];
            $this->writeOutput($payload);

            return $status === 'healthy' ? 0 : ($status === 'degraded' ? 2 : 1);
        } catch (Throwable) {
            $payload = ['status' => 'failed', 'issues' => ['runtime_dependency_unavailable']];
            $this->writeOutput($payload);
            return 1;
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeOutput(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->table(['field', 'value'], [
            ['status', $payload['status']],
            ['queue', $payload['checks']['queue'] ?? 'unknown'],
            ['cache', $payload['checks']['cache'] ?? 'unknown'],
            ['worker_heartbeats', ($payload['process']['worker_fresh_count'] ?? 0).' / '.($payload['process']['worker_expected_count'] ?? 0)],
            ['scheduler_heartbeat', ($payload['process']['scheduler_fresh'] ?? false) ? 'fresh' : 'stale'],
        ]);
    }
}
