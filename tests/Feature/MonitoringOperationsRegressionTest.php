<?php

declare(strict_types=1);

use App\Services\Ops\ProcessHeartbeatWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-01 12:00:00');
    config([
        'queue.default' => 'redis',
        'cache.default' => 'database',
        'session.encrypt' => true,
        'processes.heartbeat_ttl_seconds' => 180,
        'processes.failed_jobs_threshold' => 1,
        'processes.backlog_threshold' => 1,
        'processes.oldest_job_age_seconds' => 300,
        'processes.worker_count' => 1,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function monitoringWriteHeartbeats(?string $workerAt = null, ?string $schedulerAt = null): void
{
    $writer = app(ProcessHeartbeatWriter::class);
    Cache::store((string) config('cache.default'))->flush();

    if ($workerAt !== null) {
        Cache::store((string) config('cache.default'))->put($writer->workerRecordKey('worker-mon-1'), [
            'process_type' => 'worker',
            'process_id' => 'worker-mon-1',
            'queue_names' => ['default'],
            'instance_id' => 'test-instance',
            'started_at' => now()->subHour()->toIso8601String(),
            'last_heartbeat_at' => $workerAt,
            'status' => 'processed',
        ], now()->addHour());
        Cache::store((string) config('cache.default'))->put($writer->workerIndexKey(), ['worker-mon-1'], now()->addHour());
    }

    if ($schedulerAt !== null) {
        Cache::store((string) config('cache.default'))->put($writer->schedulerKey(), [
            'process_type' => 'scheduler',
            'process_id' => 'scheduler-mon-1',
            'queue_names' => [],
            'instance_id' => 'test-instance',
            'started_at' => now()->subHour()->toIso8601String(),
            'last_heartbeat_at' => $schedulerAt,
            'last_success_at' => $schedulerAt,
            'status' => 'ok',
        ], now()->addHour());
    }
}

it('emits machine-readable platform check json without secrets', function (): void {
    config([
        'cache.default' => 'redis',
        'queue.default' => 'redis',
        'session.encrypt' => true,
    ]);

    $exit = Artisan::call('platform:check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload['status'])->toBe('healthy')
        ->and($payload)->toHaveKeys(['checks', 'failed', 'evaluated_at'])
        ->and($payload['failed'])->toBe([])
        ->and(json_encode($payload))->not->toContain((string) config('app.key'))
        ->and(json_encode($payload))->not->toMatch('/password/i');
});

it('collapses processes health degraded into exit code 1', function (): void {
    monitoringWriteHeartbeats(now()->subMinutes(10)->toIso8601String(), null);

    $exit = Artisan::call('processes:health', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('degraded')
        ->and($exit)->toBe(1)
        ->and($payload['queue'])->toHaveKeys(['failed_jobs', 'backlog', 'oldest_job_age_seconds', 'workloads']);
});

it('reports queue backlog and oldest job age in processes health json', function (): void {
    $fresh = now()->subSeconds(10)->toIso8601String();
    monitoringWriteHeartbeats($fresh, $fresh);

    foreach ([10, 5] as $minutes) {
        DB::table('jobs')->insert([
            'queue' => 'inbound',
            'payload' => '{"displayName":"MonitoringJob"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes($minutes)->timestamp,
            'created_at' => now()->subMinutes($minutes)->timestamp,
        ]);
    }

    $exit = Artisan::call('processes:health', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('degraded')
        ->and($exit)->toBe(1)
        ->and($payload['queue']['backlog'])->toBe(2)
        ->and($payload['queue']['oldest_job_age_seconds'])->toBeGreaterThan(300)
        ->and($payload['issues'])->toContain('queue_backlog_threshold')
        ->and($payload['issues'])->toContain('oldest_job_threshold');
});

it('keeps mail-servers pool-status exit 0 and exposes summary contract fields', function (): void {
    $exit = Artisan::call('mail-servers:pool-status', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload)->toHaveKeys(['evaluated_at', 'summary', 'servers'])
        ->and($payload['summary'])->toHaveKeys(['servers', 'eligible', 'unhealthy', 'active', 'maintenance']);
});
