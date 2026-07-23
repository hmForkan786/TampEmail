<?php

use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\Ops\ProcessHeartbeatWriter;
use App\Services\Ops\ProcessReadinessService;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-23 12:00:00');

    config([
        'queue.default' => 'redis',
        'cache.default' => 'array',
        'processes.heartbeat_ttl_seconds' => 180,
        'processes.failed_jobs_threshold' => 1,
        'processes.backlog_threshold' => 1,
        'processes.oldest_job_age_seconds' => 300,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function writeProcessHeartbeats(mixed $workerAt = null, mixed $schedulerAt = null): void
{
    $writer = app(ProcessHeartbeatWriter::class);
    Cache::store((string) config('cache.default'))->flush();

    if ($workerAt !== null) {
        Cache::store((string) config('cache.default'))->put($writer->workerRecordKey('worker-test-1'), [
            'process_type' => 'worker',
            'process_id' => 'worker-test-1',
            'queue_names' => ['inbound'],
            'instance_id' => 'test-instance',
            'started_at' => now()->subHour()->toIso8601String(),
            'last_heartbeat_at' => $workerAt,
            'status' => 'processed',
        ], now()->addHour());
        Cache::store((string) config('cache.default'))->put($writer->workerIndexKey(), ['worker-test-1'], now()->addHour());
    }

    if ($schedulerAt !== null) {
        Cache::store((string) config('cache.default'))->put($writer->schedulerKey(), [
            'process_type' => 'scheduler',
            'process_id' => 'scheduler-test-1',
            'queue_names' => [],
            'instance_id' => 'test-instance',
            'started_at' => now()->subHour()->toIso8601String(),
            'last_heartbeat_at' => $schedulerAt,
            'last_success_at' => $schedulerAt,
            'status' => 'ok',
        ], now()->addHour());
    }
}

function insertQueuedJob(int $availableAt): void
{
    DB::table('jobs')->insert([
        'queue' => 'inbound',
        'payload' => '{"displayName":"TestJob"}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $availableAt,
        'created_at' => $availableAt,
    ]);
}

function insertFailedJob(string $uuid): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'inbound',
        'payload' => '{"displayName":"FailedTestJob"}',
        'exception' => 'sanitized failure',
        'failed_at' => now(),
    ]);
}

it('reports healthy when queue and scheduler heartbeats are fresh', function (): void {
    $timestamp = now()->subSeconds(30)->toIso8601String();
    config(['cache.default' => 'database']);
    writeProcessHeartbeats($timestamp, $timestamp);

    $report = app(\App\Services\Ops\ProcessReadinessService::class)->report();

    expect($report['status'])->toBe('healthy')
        ->and($report['issues'])->toBe([])
        ->and($report['lock_store']['compatible'])->toBeTrue()
        ->and($report['worker']['fresh_count'])->toBe(1)
        ->and($report['queue']['backlog'])->toBe(0)
        ->and($report['queue']['failed_jobs'])->toBe(0);
});

it('reports degraded when heartbeats are missing or stale', function (): void {
    config(['cache.default' => 'database']);
    writeProcessHeartbeats(now()->subMinutes(10)->toIso8601String(), null);

    $report = app(\App\Services\Ops\ProcessReadinessService::class)->report();

    expect($report['status'])->toBe('degraded')
        ->and($report['issues'])->toContain('worker_heartbeat_stale')
        ->and($report['issues'])->toContain('worker_heartbeat_count_mismatch')
        ->and($report['issues'])->toContain('scheduler_heartbeat_stale');
});

it('fails closed for invalid heartbeat timestamps and respects ttl boundary', function (): void {
    $boundary = now()->subSeconds((int) config('processes.heartbeat_ttl_seconds'))->toIso8601String();
    config(['cache.default' => 'database']);
    writeProcessHeartbeats($boundary, null);
    Cache::store((string) config('cache.default'))->put(app(ProcessHeartbeatWriter::class)->schedulerKey(), [
        'process_type' => 'scheduler',
        'process_id' => 'scheduler-test-1',
        'queue_names' => [],
        'instance_id' => 'test-instance',
        'started_at' => now()->subHour()->toIso8601String(),
        'last_heartbeat_at' => 'not-a-timestamp',
        'status' => 'ok',
    ], now()->addHour());

    $report = app(\App\Services\Ops\ProcessReadinessService::class)->report();

    expect($report['status'])->toBe('degraded')
        ->and($report['issues'])->not->toContain('worker_heartbeat_stale')
        ->and($report['issues'])->toContain('scheduler_heartbeat_stale');
});

it('marks sync queue and incompatible lock stores as degraded', function (): void {
    config([
        'queue.default' => 'sync',
        'cache.default' => 'file',
    ]);
    $timestamp = now()->subSeconds(10)->toIso8601String();
    writeProcessHeartbeats($timestamp, $timestamp);

    $report = app(\App\Services\Ops\ProcessReadinessService::class)->report();

    expect($report['status'])->toBe('degraded')
        ->and($report['issues'])->toContain('queue_connection_sync', 'lock_store_incompatible')
        ->and($report['lock_store']['compatible'])->toBeFalse();
});

it('treats database lock store as compatible', function (): void {
    config(['cache.default' => 'database']);
    $timestamp = now()->subSeconds(10)->toIso8601String();
    writeProcessHeartbeats($timestamp, $timestamp);

    $report = app(\App\Services\Ops\ProcessReadinessService::class)->report();

    expect($report['status'])->toBe('healthy')
        ->and($report['lock_store']['compatible'])->toBeTrue();
});

it('reports queue backlog oldest age and failed job thresholds as degraded', function (): void {
    $timestamp = now()->subSeconds(10)->toIso8601String();
    config(['cache.default' => 'database']);
    writeProcessHeartbeats($timestamp, $timestamp);
    insertQueuedJob(now()->subMinutes(10)->timestamp);
    insertQueuedJob(now()->subMinutes(5)->timestamp);
    insertFailedJob((string) str()->uuid());
    insertFailedJob((string) str()->uuid());

    $report = app(\App\Services\Ops\ProcessReadinessService::class)->report();

    expect($report['status'])->toBe('degraded')
        ->and($report['issues'])->toContain('queue_backlog_threshold', 'oldest_job_threshold', 'failed_jobs_threshold')
        ->and($report['queue']['backlog'])->toBe(2)
        ->and($report['queue']['failed_jobs'])->toBe(2)
        ->and($report['queue']['oldest_job_age_seconds'])->toBeGreaterThan(300);
});

it('returns failed when queue tables are unavailable without crashing the command', function (): void {
    config(['cache.default' => 'database']);
    writeProcessHeartbeats(now()->toIso8601String(), now()->toIso8601String());
    DB::statement('DROP TABLE jobs');

    $report = app(\App\Services\Ops\ProcessReadinessService::class)->report();

    expect($report['status'])->toBe('failed')
        ->and($report['queue']['backlog'])->toBe(-1)
        ->and($report['queue']['oldest_job_age_seconds'])->toBe(-1);

    Artisan::call('processes:health');
    expect(Artisan::output())->toContain('failed');
});

it('exposes safe json output and degraded exit code when heartbeats are missing', function (): void {
    writeProcessHeartbeats();

    $exitCode = Artisan::call('processes:health', ['--json' => true]);
    expect($exitCode)->toBe(1);

    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json)->toHaveKeys(['status', 'issues', 'queue', 'worker', 'scheduler', 'lock_store'])
        ->and($json['status'])->toBe('degraded')
        ->and($json['queue'])->toHaveKeys(['connection', 'failed_jobs', 'backlog', 'oldest_job_age_seconds'])
        ->and($json['worker'])->toHaveKeys(['expected_count', 'fresh_count', 'records'])
        ->and($json['scheduler'])->toHaveKeys(['fresh', 'record'])
        ->and(json_encode($json))->not->toContain('token')
        ->and(json_encode($json))->not->toContain('secret')
        ->and(json_encode($json))->not->toContain('payload')
        ->and(json_encode($json))->not->toContain('exception');
});

it('exposes healthy and failed command exit codes for the report matrix', function (): void {
    $timestamp = now()->subSeconds(5)->toIso8601String();
    config(['cache.default' => 'database']);
    writeProcessHeartbeats($timestamp, $timestamp);

    expect(Artisan::call('processes:health'))->toBe(0)
        ->and(Artisan::output())->toContain('healthy');

    DB::statement('DROP TABLE failed_jobs');
    writeProcessHeartbeats($timestamp, $timestamp);

    expect(Artisan::call('processes:health', ['--json' => true]))->toBe(1);

    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json['status'])->toBe('failed');
});

function bindFailingReadinessService(string $message): void
{
    $cache = Mockery::mock(CacheFactory::class);
    $cache->shouldReceive('store')->andThrow(new RuntimeException($message));
    app()->instance(ProcessReadinessService::class, new ProcessReadinessService(new ProcessHeartbeatWriter($cache)));
}

it('returns bounded json when readiness throws a sensitive exception', function (): void {
    bindFailingReadinessService('Redis password=secret token=abc at C:\\app\\database.php:42; stack trace hidden');

    expect(Artisan::call('processes:health', ['--json' => true]))->toBe(1);

    $output = Artisan::output();
    $json = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($json)->toBe([
        'status' => 'failed',
        'evaluated_at' => now()->toIso8601String(),
        'reasons' => ['process_readiness_unavailable'],
    ])
        ->and($output)->not->toContain('Redis password')
        ->and($output)->not->toContain('secret')
        ->and($output)->not->toContain('RuntimeException')
        ->and($output)->not->toContain('database.php')
        ->and($output)->not->toContain('stack trace');
});

it('returns bounded non-json failure output when readiness throws', function (): void {
    bindFailingReadinessService('mysql://user:password@host/db token=secret C:\\var\\www\\app.php:9');

    expect(Artisan::call('processes:health'))->toBe(1);

    $output = Artisan::output();
    expect($output)->toContain('failed')
        ->and($output)->toContain('process_readiness_unavailable')
        ->and($output)->not->toContain('mysql://')
        ->and($output)->not->toContain('password')
        ->and($output)->not->toContain('secret')
        ->and($output)->not->toContain('RuntimeException')
        ->and($output)->not->toContain('app.php');
});
