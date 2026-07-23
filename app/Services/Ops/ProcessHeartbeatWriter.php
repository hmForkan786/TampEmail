<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class ProcessHeartbeatWriter
{
    private const WORKER_INDEX_KEY = 'process.heartbeat.worker.index';
    private const WORKER_PREFIX = 'process.heartbeat.worker.';
    private const SCHEDULER_KEY = 'process.heartbeat.scheduler';

    private array $lastWrittenAt = [];

    private array $processStartedAt = [];

    public function __construct(
        private readonly CacheFactory $cache,
    ) {}

    public function recordWorkerStarting(string $connectionName, string $queueNames, ?string $processId = null): bool
    {
        return $this->writeWorkerHeartbeat('starting', $this->normalizeQueueNames($queueNames), $processId, true);
    }

    public function recordWorkerLoop(string $connectionName, string $queueNames, ?string $processId = null): bool
    {
        return $this->writeWorkerHeartbeat('idle', $this->normalizeQueueNames($queueNames), $processId, false);
    }

    public function recordWorkerProcessed(string $queueName, ?string $processId = null): bool
    {
        return $this->writeWorkerHeartbeat('processed', $this->normalizeQueueNames($queueName), $processId, true);
    }

    public function recordWorkerFailed(string $queueName, ?string $processId = null): bool
    {
        return $this->writeWorkerHeartbeat('failed', $this->normalizeQueueNames($queueName), $processId, true);
    }

    public function recordWorkerStopping(?string $processId = null): bool
    {
        return $this->writeWorkerHeartbeat('stopped', [], $processId, true);
    }

    public function recordSchedulerStarting(?string $processId = null): bool
    {
        $record = $this->schedulerRecord($processId);
        $record['status'] = 'running';
        $record['last_heartbeat_at'] = now()->toIso8601String();
        unset($record['error_code']);

        return $this->persist(self::SCHEDULER_KEY, $record);
    }

    public function recordSchedulerSucceeded(?string $processId = null): bool
    {
        $timestamp = now()->toIso8601String();
        $record = $this->schedulerRecord($processId);
        $record['status'] = 'ok';
        $record['last_heartbeat_at'] = $timestamp;
        $record['last_success_at'] = $timestamp;
        unset($record['error_code']);

        return $this->persist(self::SCHEDULER_KEY, $record);
    }

    public function recordSchedulerFailed(\Throwable $exception, ?string $processId = null): bool
    {
        $timestamp = now()->toIso8601String();
        $record = $this->schedulerRecord($processId);
        $record['status'] = 'failed';
        $record['last_heartbeat_at'] = $timestamp;
        $record['last_failed_at'] = $timestamp;
        $record['error_code'] = $this->safeErrorCode($exception);

        return $this->persist(self::SCHEDULER_KEY, $record);
    }

    public function schedulerTick(): bool
    {
        return $this->recordSchedulerSucceeded();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function workerRecords(): array
    {
        $index = $this->cacheStore()->get(self::WORKER_INDEX_KEY, []);
        if (! is_array($index)) {
            return [];
        }

        $records = [];
        foreach ($index as $processId) {
            $record = $this->cacheStore()->get(self::WORKER_PREFIX.$processId);
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        usort($records, fn (array $left, array $right): int => strcmp((string) ($right['last_heartbeat_at'] ?? ''), (string) ($left['last_heartbeat_at'] ?? '')));

        return $records;
    }

    public function currentSchedulerRecord(): ?array
    {
        $record = $this->cacheStore()->get(self::SCHEDULER_KEY);

        return is_array($record) ? $record : null;
    }

    public function workerIndexKey(): string
    {
        return self::WORKER_INDEX_KEY;
    }

    public function workerRecordKey(string $processId): string
    {
        return self::WORKER_PREFIX.$processId;
    }

    public function schedulerKey(): string
    {
        return self::SCHEDULER_KEY;
    }

    private function writeWorkerHeartbeat(string $status, array $queueNames, ?string $processId, bool $force): bool
    {
        if ((string) config('queue.default') === 'sync') {
            return false;
        }

        $processId ??= $this->defaultProcessId('worker');
        if (! $force && ! $this->shouldWrite($processId)) {
            return true;
        }

        $record = $this->cacheStore()->get($this->workerRecordKey($processId), $this->workerRecord($processId));
        if (! is_array($record)) {
            $record = $this->workerRecord($processId);
        }

        $record['status'] = $status;
        $record['queue_names'] = $queueNames === [] ? ($record['queue_names'] ?? []) : $queueNames;
        $record['last_heartbeat_at'] = now()->toIso8601String();

        if ($status === 'processed') {
            $record['last_success_at'] = $record['last_heartbeat_at'];
        }

        if ($status === 'failed') {
            $record['last_failed_at'] = $record['last_heartbeat_at'];
        }

        return $this->persistWorkerRecord($processId, $record);
    }

    private function persistWorkerRecord(string $processId, array $record): bool
    {
        if (! $this->persist($this->workerRecordKey($processId), $record)) {
            return false;
        }

        try {
            $lock = $this->cacheStore()->lock('process.heartbeat.worker.index.lock', 2);

            return (bool) $lock->get(function () use ($processId): bool {
                $index = $this->cacheStore()->get(self::WORKER_INDEX_KEY, []);
                $index = is_array($index) ? $index : [];
                $index = array_values(array_unique([...$index, $processId]));

                // Remove entries whose bounded heartbeat record has expired.
                $index = array_values(array_filter($index, fn (mixed $id): bool => is_string($id) && is_array($this->cacheStore()->get($this->workerRecordKey($id)))));
                $limit = max(1, (int) config('processes.heartbeat_record_limit', 16));
                $index = array_slice($index, -1 * $limit);

                return $this->persist(self::WORKER_INDEX_KEY, $index);
            });
        } catch (\Throwable $exception) {
            Log::warning('Process heartbeat registry update failed.', [
                'error_code' => $this->safeErrorCode($exception),
            ]);

            return false;
        }
    }

    private function persist(string $key, array $value): bool
    {
        try {
            $this->cacheStore()->put($key, $value, now()->addSeconds($this->ttlSeconds() * 2));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Process heartbeat write failed.', [
                'key' => $this->safeKeyName($key),
                'error_code' => $this->safeErrorCode($exception),
            ]);

            return false;
        }
    }

    private function shouldWrite(string $processId): bool
    {
        $interval = max(1, (int) config('processes.heartbeat_write_interval_seconds', 30));
        $timestamp = now()->timestamp;
        $lastWrittenAt = $this->lastWrittenAt[$processId] ?? null;

        if ($lastWrittenAt !== null && ($timestamp - $lastWrittenAt) < $interval) {
            return false;
        }

        $this->lastWrittenAt[$processId] = $timestamp;

        return true;
    }

    private function workerRecord(string $processId): array
    {
        return [
            'process_type' => 'worker',
            'process_id' => $processId,
            'queue_names' => [],
            'instance_id' => $this->instanceId(),
            'started_at' => $this->startedAt($processId),
            'last_heartbeat_at' => now()->toIso8601String(),
            'status' => 'starting',
        ];
    }

    private function schedulerRecord(?string $processId): array
    {
        $processId ??= $this->defaultProcessId('scheduler');

        return array_merge([
            'process_type' => 'scheduler',
            'process_id' => $processId,
            'queue_names' => [],
            'instance_id' => $this->instanceId(),
            'started_at' => $this->startedAt($processId),
            'last_heartbeat_at' => now()->toIso8601String(),
            'status' => 'running',
        ], $this->currentSchedulerRecord() ?? []);
    }

    private function defaultProcessId(string $processType): string
    {
        $pid = getmypid();

        return sprintf('%s-%s-%s', $processType, $this->instanceId(), $pid !== false ? (string) $pid : 'na');
    }

    private function startedAt(string $processId): string
    {
        return $this->processStartedAt[$processId] ??= now()->toIso8601String();
    }

    /**
     * @return list<string>
     */
    private function normalizeQueueNames(array|string|null $queueNames): array
    {
        $values = is_array($queueNames) ? $queueNames : explode(',', (string) $queueNames);
        $values = array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $values)));

        return array_slice(array_values(array_unique($values)), 0, 10);
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('processes.heartbeat_ttl_seconds', 180));
    }

    private function instanceId(): string
    {
        $configured = trim((string) config('processes.instance_id', ''));
        if ($configured !== '') {
            return substr(hash('sha256', $configured), 0, 16);
        }

        return substr(hash('sha256', gethostname() ?: 'unknown-host'), 0, 16);
    }

    private function safeErrorCode(\Throwable $exception): string
    {
        $name = preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($exception)) ?? 'error';

        return substr(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name) ?? 'error'), 0, 64);
    }

    private function safeKeyName(string $key): string
    {
        if ($key === self::SCHEDULER_KEY || $key === self::WORKER_INDEX_KEY) {
            return $key;
        }

        return str_starts_with($key, self::WORKER_PREFIX) ? self::WORKER_PREFIX.'*' : 'unknown';
    }

    private function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        return $this->cache->store((string) config('cache.default'));
    }
}
