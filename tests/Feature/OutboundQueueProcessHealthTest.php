<?php

declare(strict_types=1);

use App\Services\Ops\ProcessHeartbeatWriter;
use App\Services\Outbound\OutboundQueueReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function writeOutboundWorkerHeartbeat(string $processId, array $queueNames, ?string $heartbeatAt = null): void
{
    $writer = app(ProcessHeartbeatWriter::class);
    Cache::store((string) config('cache.default'))->put($writer->workerRecordKey($processId), [
        'process_type' => 'worker',
        'process_id' => $processId,
        'queue_names' => $queueNames,
        'instance_id' => 'test-instance',
        'started_at' => now()->subHour()->toIso8601String(),
        'last_heartbeat_at' => $heartbeatAt ?? now()->toIso8601String(),
        'status' => 'processed',
    ], now()->addHour());

    $index = Cache::store((string) config('cache.default'))->get($writer->workerIndexKey(), []);
    $index = is_array($index) ? $index : [];
    Cache::store((string) config('cache.default'))->put($writer->workerIndexKey(), array_values(array_unique([...$index, $processId])), now()->addHour());
}

function writeOutboundSchedulerHeartbeat(?string $successAt): void
{
    $writer = app(ProcessHeartbeatWriter::class);
    Cache::store((string) config('cache.default'))->put($writer->schedulerKey(), [
        'process_type' => 'scheduler',
        'process_id' => 'scheduler-outbound-test',
        'queue_names' => [],
        'instance_id' => 'test-instance',
        'started_at' => now()->subHour()->toIso8601String(),
        'last_heartbeat_at' => $successAt ?? now()->toIso8601String(),
        'last_success_at' => $successAt,
        'status' => $successAt !== null ? 'ok' : 'running',
    ], now()->addHour());
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-25 18:00:00');
    config([
        'cache.default' => 'array',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 120,
        'processes.heartbeat_ttl_seconds' => 180,
        'processes.backlog_threshold' => 100,
        'processes.failed_jobs_threshold' => 10,
        'processes.oldest_job_age_seconds' => 900,
        'outbound.worker.delivery_count' => 1,
        'outbound.worker.events_count' => 1,
        'outbound.smtp.timeout' => 30,
        'outbound.worker.job_timeout_seconds' => 60,
    ]);
    Cache::store('array')->flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('reports healthy when delivery, events, and maintenance heartbeats are all fresh', function (): void {
    writeOutboundWorkerHeartbeat('delivery-1', ['outbound-delivery']);
    writeOutboundWorkerHeartbeat('events-1', ['outbound-events']);
    writeOutboundSchedulerHeartbeat(now()->toIso8601String());

    $report = app(OutboundQueueReadinessService::class)->report();

    expect($report['status'])->toBe('healthy')
        ->and($report['issues'])->toBe([])
        ->and($report['delivery']['fresh_workers'])->toBe(1)
        ->and($report['events']['fresh_workers'])->toBe(1)
        ->and($report['maintenance']['scheduler_fresh'])->toBeTrue();
});

it('degrades when the delivery worker heartbeat is missing', function (): void {
    writeOutboundWorkerHeartbeat('events-1', ['outbound-events']);
    writeOutboundSchedulerHeartbeat(now()->toIso8601String());

    $report = app(OutboundQueueReadinessService::class)->report();

    expect($report['status'])->toBe('degraded')
        ->and($report['issues'])->toContain('delivery_worker_heartbeat_missing')
        ->and($report['delivery']['fresh_workers'])->toBe(0);
});

it('degrades when the provider-event worker heartbeat is stale', function (): void {
    writeOutboundWorkerHeartbeat('delivery-1', ['outbound-delivery']);
    writeOutboundWorkerHeartbeat('events-1', ['outbound-events'], now()->subMinutes(10)->toIso8601String());
    writeOutboundSchedulerHeartbeat(now()->toIso8601String());

    $report = app(OutboundQueueReadinessService::class)->report();

    expect($report['status'])->toBe('degraded')
        ->and($report['issues'])->toContain('events_worker_heartbeat_missing');
});

it('degrades when the maintenance scheduler heartbeat is stale', function (): void {
    writeOutboundWorkerHeartbeat('delivery-1', ['outbound-delivery']);
    writeOutboundWorkerHeartbeat('events-1', ['outbound-events']);
    writeOutboundSchedulerHeartbeat(now()->subMinutes(10)->toIso8601String());

    $report = app(OutboundQueueReadinessService::class)->report();

    expect($report['status'])->toBe('degraded')
        ->and($report['issues'])->toContain('maintenance_scheduler_heartbeat_stale')
        ->and($report['maintenance']['scheduler_fresh'])->toBeFalse();
});

it('degrades on per-queue backlog, oldest-job-age, and failed-job thresholds', function (): void {
    writeOutboundWorkerHeartbeat('delivery-1', ['outbound-delivery']);
    writeOutboundWorkerHeartbeat('events-1', ['outbound-events']);
    writeOutboundSchedulerHeartbeat(now()->toIso8601String());
    config(['processes.backlog_threshold' => 1, 'processes.failed_jobs_threshold' => 1, 'processes.oldest_job_age_seconds' => 300]);

    DB::table('jobs')->insert([
        ['queue' => 'outbound-delivery', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(20)->timestamp, 'created_at' => now()->subMinutes(20)->timestamp],
        ['queue' => 'outbound-delivery', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
    ]);
    DB::table('failed_jobs')->insert([
        ['uuid' => (string) str()->uuid(), 'connection' => 'redis', 'queue' => 'outbound-delivery', 'payload' => '{}', 'exception' => 'sanitized', 'failed_at' => now()],
        ['uuid' => (string) str()->uuid(), 'connection' => 'redis', 'queue' => 'outbound-delivery', 'payload' => '{}', 'exception' => 'sanitized', 'failed_at' => now()],
    ]);

    $report = app(OutboundQueueReadinessService::class)->report();

    expect($report['status'])->toBe('degraded')
        ->and($report['issues'])->toContain('delivery_backlog_threshold', 'delivery_failed_jobs_threshold', 'delivery_oldest_job_threshold')
        ->and($report['delivery']['backlog'])->toBe(2)
        ->and($report['delivery']['failed_jobs'])->toBe(2)
        ->and($report['events']['backlog'])->toBe(0);
});

it('fails closed when the worker timeout ordering is invalid', function (): void {
    writeOutboundWorkerHeartbeat('delivery-1', ['outbound-delivery']);
    writeOutboundWorkerHeartbeat('events-1', ['outbound-events']);
    writeOutboundSchedulerHeartbeat(now()->toIso8601String());
    config(['outbound.worker.job_timeout_seconds' => 10]);

    $report = app(OutboundQueueReadinessService::class)->report();

    expect($report['status'])->toBe('failed')
        ->and($report['issues'])->toContain('invalid_worker_timeout_config')
        ->and($report['worker_config']['valid'])->toBeFalse();
});

it('exposes queue readiness through outbound:status --json without breaking the generic process health command', function (): void {
    writeOutboundWorkerHeartbeat('delivery-1', ['outbound-delivery']);
    writeOutboundWorkerHeartbeat('events-1', ['outbound-events']);
    writeOutboundSchedulerHeartbeat(now()->toIso8601String());

    $exit = Artisan::call('outbound:status', ['--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBeIn([0, 1, 2])
        ->and($json)->toHaveKey('queue')
        ->and($json['queue'])->toHaveKeys(['status', 'topology', 'worker_config', 'delivery', 'events', 'maintenance'])
        ->and(json_encode($json))->not->toContain('password')
        ->and(json_encode($json))->not->toContain('secret');

    // Regression guard: the generic process health command is untouched.
    expect(Artisan::call('processes:health', ['--json' => true]))->toBeIn([0, 1]);
});
