<?php

declare(strict_types=1);

use App\Services\Ops\ProcessHeartbeatWriter;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-24 11:00:00');

    config([
        'cache.default' => 'array',
        'queue.default' => 'database',
        'processes.heartbeat_ttl_seconds' => 180,
        'processes.heartbeat_write_interval_seconds' => 30,
    ]);

    Cache::store((string) config('cache.default'))->flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('writes a scheduler heartbeat with a deterministic timestamp', function (): void {
    $exitCode = Artisan::call('processes:scheduler-heartbeat');
    $record = app(ProcessHeartbeatWriter::class)->currentSchedulerRecord();

    expect($exitCode)->toBe(0)
        ->and($record)->not->toBeNull()
        ->and($record['status'])->toBe('ok')
        ->and($record['last_heartbeat_at'])->toBe(now()->toIso8601String())
        ->and($record['last_success_at'])->toBe(now()->toIso8601String())
        ->and(Artisan::output())->toContain('Scheduler heartbeat written.');
});

it('refreshes an existing scheduler heartbeat without mutating worker heartbeats', function (): void {
    $writer = app(ProcessHeartbeatWriter::class);
    $writer->recordWorkerStarting('database', 'inbound', 'worker-keep');
    Cache::store((string) config('cache.default'))->put($writer->schedulerKey(), [
        'process_type' => 'scheduler',
        'process_id' => 'scheduler-keep',
        'queue_names' => [],
        'instance_id' => 'test-instance',
        'started_at' => now()->subHour()->toIso8601String(),
        'last_heartbeat_at' => now()->subMinutes(2)->toIso8601String(),
        'last_success_at' => now()->subMinutes(2)->toIso8601String(),
        'status' => 'ok',
    ], now()->addHour());

    $workerBefore = $writer->workerRecords();
    Carbon::setTestNow(now()->addMinute());

    expect(Artisan::call('processes:scheduler-heartbeat'))->toBe(0);

    $record = $writer->currentSchedulerRecord();
    expect($record['last_heartbeat_at'])->toBe(now()->toIso8601String())
        ->and($record['last_success_at'])->toBe(now()->toIso8601String())
        ->and($record['status'])->toBe('ok')
        ->and($writer->workerRecords())->toBe($workerBefore);
});

it('returns a safe non-zero exit code when the heartbeat write fails', function (): void {
    $cache = Mockery::mock(CacheFactory::class);
    $store = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $cache->shouldReceive('store')->andReturn($store);
    $store->shouldReceive('get')->andReturn(null);
    $store->shouldReceive('put')->andThrow(new RuntimeException('cache down'));
    app()->instance(ProcessHeartbeatWriter::class, new ProcessHeartbeatWriter($cache));

    $exitCode = Artisan::call('processes:scheduler-heartbeat');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Scheduler heartbeat write failed.')
        ->and($output)->not->toContain('redis')
        ->and($output)->not->toContain('Exception')
        ->and($output)->not->toContain('password')
        ->and($output)->not->toContain('secret')
        ->and($output)->not->toContain('cache down');
});

it('returns a safe non-zero exit code when the writer throws', function (): void {
    $cache = Mockery::mock(CacheFactory::class);
    $cache->shouldReceive('store')->andThrow(new RuntimeException('redis://secret-host:6379 auth=super-secret'));
    app()->instance(ProcessHeartbeatWriter::class, new ProcessHeartbeatWriter($cache));

    $exitCode = Artisan::call('processes:scheduler-heartbeat');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Scheduler heartbeat unavailable.')
        ->and($output)->not->toContain('secret-host')
        ->and($output)->not->toContain('super-secret')
        ->and($output)->not->toContain('redis://')
        ->and($output)->not->toContain('RuntimeException');
});

it('registers the explicit scheduler heartbeat command every minute without an anonymous closure', function (): void {
    $this->refreshApplication();
    Carbon::setTestNow('2026-07-24 11:00:00');
    config([
        'cache.default' => 'array',
        'queue.default' => 'database',
        'processes.heartbeat_ttl_seconds' => 180,
        'processes.heartbeat_write_interval_seconds' => 30,
    ]);

    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect(substr_count($output, 'processes:scheduler-heartbeat'))->toBe(1)
        ->and($output)->toMatch('/\* \* \* \* \* .*processes:scheduler-heartbeat/s');

    $events = collect(app(Schedule::class)->events());
    $heartbeatEvents = $events->filter(function (object $event): bool {
        return str_contains((string) ($event->command ?? ''), 'processes:scheduler-heartbeat');
    });

    expect($heartbeatEvents)->toHaveCount(1);

    $event = $heartbeatEvents->first();
    expect($event)->not->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();

    expect($events->filter(fn (object $event): bool => $event instanceof CallbackEvent))->toHaveCount(0);

    $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
    expect($bootstrap)->toContain("command('processes:scheduler-heartbeat')")
        ->and($bootstrap)->toContain('everyMinute()')
        ->and($bootstrap)->toContain('withoutOverlapping()')
        ->and($bootstrap)->not->toContain('schedulerTick()')
        ->and($bootstrap)->not->toMatch('/\$schedule->call\s*\(/');
});
