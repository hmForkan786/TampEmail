<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Services\Ops\ProcessHeartbeatWriter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['queue.default' => 'database', 'cache.default' => 'database', 'processes.worker_count' => 0]);
    Cache::store('database')->put(app(ProcessHeartbeatWriter::class)->schedulerKey(), ['last_success_at' => now()->toIso8601String()], now()->addHour());
});

it('reports healthy simulated topology with safe json and no process spawning', function (): void {
    expect(Artisan::call('processes:runtime-smoke', ['--json' => true]))->toBe(0);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($json['status'])->toBe('healthy')->and($json['checks'])->toHaveKeys(['queue', 'cache'])
        ->and(Artisan::output())->not->toContain('password')->not->toContain('redis://');
});

it('reports degraded sync or unsupported stores', function (): void {
    config(['queue.default' => 'sync', 'cache.default' => 'file']);
    expect(Artisan::call('processes:runtime-smoke', ['--json' => true]))->toBe(2);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('degraded');
});

it('fails safely when redis metadata is unavailable', function (): void {
    config(['queue.default' => 'redis', 'cache.default' => 'array']);
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('redis://secret-host password=secret'));
    expect(Artisan::call('processes:runtime-smoke', ['--json' => true]))->toBe(1)
        ->and(Artisan::output())->not->toContain('secret-host')->not->toContain('password');
});
