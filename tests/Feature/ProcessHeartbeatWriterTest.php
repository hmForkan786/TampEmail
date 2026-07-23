<?php

use App\Services\Ops\ProcessHeartbeatWriter;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event as EventFacade;
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-23 13:00:00');
    config([
        'cache.default' => 'array',
        'queue.default' => 'database',
        'processes.heartbeat_ttl_seconds' => 180,
        'processes.heartbeat_write_interval_seconds' => 30,
        'processes.worker_count' => 2,
    ]);
    Cache::store((string) config('cache.default'))->flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function schedulerEvent(string $command = 'php artisan inboxes:expire --confirm'): Event
{
    $event = new Event(new CacheEventMutex(app('cache')), $command);
    $event->description('scheduler-heartbeat-test');

    return $event;
}

function queueJob(string $queue = 'inbound'): \Illuminate\Contracts\Queue\Job
{
    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('getQueue')->andReturn($queue);

    return $job;
}

it('writes worker heartbeats from lifecycle events and throttles loop writes', function (): void {
    EventFacade::dispatch(new WorkerStarting('database', 'inbound,attachment-scans', new WorkerOptions));

    $writer = app(ProcessHeartbeatWriter::class);
    $records = $writer->workerRecords();
    expect($records)->toHaveCount(1)
        ->and($records[0]['status'])->toBe('starting')
        ->and($records[0]['queue_names'])->toBe(['inbound', 'attachment-scans']);

    $firstHeartbeat = $records[0]['last_heartbeat_at'];
    EventFacade::dispatch(new Looping('database', 'inbound'));
    EventFacade::dispatch(new Looping('database', 'inbound'));

    $records = $writer->workerRecords();
    expect($records[0]['last_heartbeat_at'])->toBe($firstHeartbeat);

    Carbon::setTestNow(now()->addSeconds(31));
    EventFacade::dispatch(new Looping('database', 'inbound'));

    expect($writer->workerRecords()[0]['last_heartbeat_at'])->not->toBe($firstHeartbeat);
});

it('updates safe worker status for processed failed and stopping events', function (): void {
    EventFacade::dispatch(new WorkerStarting('database', 'inbound', new WorkerOptions));
    EventFacade::dispatch(new JobProcessed('database', queueJob('attachment-scans')));

    $writer = app(ProcessHeartbeatWriter::class);
    $record = $writer->workerRecords()[0];
    expect($record['status'])->toBe('processed')
        ->and($record)->toHaveKey('last_success_at')
        ->and(json_encode($record))->not->toContain('payload')
        ->and(json_encode($record))->not->toContain('secret');

    EventFacade::dispatch(new JobFailed('database', queueJob('attachment-scans'), new RuntimeException('boom')));
    expect($writer->workerRecords()[0]['status'])->toBe('failed');

    EventFacade::dispatch(new WorkerStopping);
    expect($writer->workerRecords()[0]['status'])->toBe('stopped');
});

it('does not impersonate a live worker on sync queue execution', function (): void {
    config(['queue.default' => 'sync']);

    EventFacade::dispatch(new WorkerStarting('sync', 'default', new WorkerOptions));

    expect(app(ProcessHeartbeatWriter::class)->workerRecords())->toBe([]);
});

it('writes scheduler heartbeats for starting success and failure events', function (): void {
    $event = schedulerEvent();
    EventFacade::dispatch(new ScheduledTaskStarting($event));

    $writer = app(ProcessHeartbeatWriter::class);
    expect($writer->currentSchedulerRecord()['status'])->toBe('running');

    EventFacade::dispatch(new ScheduledTaskFinished($event, 0.5));
    $record = $writer->currentSchedulerRecord();
    expect($record['status'])->toBe('ok')
        ->and($record)->toHaveKey('last_success_at')
        ->and(json_encode($record))->not->toContain('--confirm');

    EventFacade::dispatch(new ScheduledTaskFailed($event, new RuntimeException('scheduler failed')));
    $record = $writer->currentSchedulerRecord();
    expect($record['status'])->toBe('failed')
        ->and($record['error_code'])->toBe('runtime_exception');
});

it('expires stale worker heartbeats and readiness reads live writer records', function (): void {
    $writer = app(ProcessHeartbeatWriter::class);
    $writer->recordWorkerStarting('database', 'inbound', 'worker-a');
    $writer->recordWorkerStarting('database', 'attachment-scans', 'worker-b');
    $writer->recordSchedulerSucceeded('scheduler-a');

    Carbon::setTestNow(now()->addSeconds(181));

    $report = app(\App\Services\Ops\ProcessReadinessService::class)->report();
    expect($report['status'])->toBe('degraded')
        ->and($report['issues'])->toContain('worker_heartbeat_stale')
        ->and($report['issues'])->toContain('worker_heartbeat_count_mismatch')
        ->and($report['issues'])->toContain('scheduler_heartbeat_stale');
});

it('handles cache write failures without blocking business flow', function (): void {
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Factory::class);
    $store = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $cache->shouldReceive('store')->andReturn($store);
    $store->shouldReceive('get')->andReturn(null);
    $store->shouldReceive('put')->andThrow(new RuntimeException('cache down'));

    $writer = new ProcessHeartbeatWriter($cache);

    expect(fn () => $writer->recordWorkerStarting('database', 'inbound', 'worker-test'))->not->toThrow(RuntimeException::class)
        ->and($writer->recordSchedulerSucceeded('scheduler-test'))->toBeFalse();
});

it('supports multiple worker identities with bounded non-sensitive records', function (): void {
    $writer = app(ProcessHeartbeatWriter::class);
    $writer->recordWorkerStarting('database', 'inbound', 'worker-a');
    $writer->recordWorkerStarting('database', 'attachment-scans', 'worker-b');

    $records = $writer->workerRecords();
    $processIds = collect($records)->pluck('process_id')->all();
    expect($records)->toHaveCount(2)
        ->and($processIds)->toContain('worker-a')
        ->and($processIds)->toContain('worker-b')
        ->and(json_encode($records))->not->toContain('authorization')
        ->and(json_encode($records))->not->toContain('email');
});
