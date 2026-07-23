<?php

use App\Filament\Admin\Pages\ProcessHealth;
use App\Models\User;
use App\Services\Ops\ProcessHeartbeatWriter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function processHealthReport(string $status = 'healthy'): array
{
    return [
        'status' => $status,
        'issues' => $status === 'healthy' ? [] : ['worker_heartbeat_stale', 'lock_store_incompatible'],
        'queue' => ['connection' => 'database', 'backlog' => 2, 'oldest_job_age_seconds' => 12, 'failed_jobs' => 0],
        'lock_store' => ['cache' => 'redis', 'compatible' => true],
        'worker' => ['expected_count' => 1, 'fresh_count' => 1, 'records' => [['process_type' => 'worker', 'queue_names' => ['inbound'], 'started_at' => '2026-07-23T12:00:00+06:00', 'last_heartbeat_at' => '2026-07-23T12:00:30+06:00', 'status' => 'running', 'process_id' => 'opaque-worker-id', 'instance_id' => 'opaque-instance-hash']]],
        'scheduler' => ['fresh' => true, 'record' => ['status' => 'running', 'last_heartbeat_at' => '2026-07-23T12:00:30+06:00']],
    ];
}

it('allows platform admins to view safe process health data', function (): void {
    config()->set(['queue.default' => 'database', 'cache.default' => 'database']);
    app(ProcessHeartbeatWriter::class)->recordWorkerStarting('database', 'inbound', 'opaque-worker-id');
    app(ProcessHeartbeatWriter::class)->recordSchedulerSucceeded('opaque-scheduler-id');
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get('/admin/process-health')->assertOk()->assertSee('healthy')->assertSee('database')->assertSee('inbound')->assertSee('starting')->assertDontSee('opaque-worker-id')->assertDontSee('opaque-instance-hash');
    expect(ProcessHealth::canAccess())->toBeTrue()->and(ProcessHealth::shouldRegisterNavigation())->toBeTrue();
});

it('denies unauthorized users and hides navigation', function (): void {
    $operator = User::factory()->platformOperator()->create();
    $user = User::factory()->create();
    $this->actingAs($operator)->get('/admin/process-health')->assertForbidden();
    $this->actingAs($user)->get('/admin/process-health')->assertForbidden();
    expect(ProcessHealth::canAccess())->toBeFalse()->and(ProcessHealth::shouldRegisterNavigation())->toBeFalse();
});

it('requires authentication for guests', function (): void {
    $this->get('/admin/process-health')->assertRedirect();
    expect(ProcessHealth::canAccess())->toBeFalse()->and(ProcessHealth::shouldRegisterNavigation())->toBeFalse();
});

it('renders degraded state and safe reasons', function (): void {
    config()->set(['queue.default' => 'sync', 'cache.default' => 'file']);
    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin)->get('/admin/process-health')->assertOk()->assertSee('degraded')->assertSee('worker_heartbeat_stale')->assertSee('lock_store_incompatible');
});

it('renders a safe unavailable state without performing mutations', function (): void {
    config()->set(['queue.default' => 'sync', 'cache.default' => 'file']);
    $admin = User::factory()->platformAdmin()->create();
    $before = User::query()->count();
    $this->actingAs($admin)->get('/admin/process-health')->assertOk()->assertSee('degraded')->assertDontSee('secret token')->assertDontSee('stack trace');
    expect(User::query()->count())->toBe($before);
});

it('renders failed status and malformed reports fail closed', function (): void {
    $reflection = new ReflectionMethod(ProcessHealth::class, 'safeReport');
    $reflection->setAccessible(true);
    $page = app(ProcessHealth::class);

    expect($reflection->invoke($page, array_merge(processHealthReport('failed'), [
        'issues' => ['internal_exception', 'worker_heartbeat_stale'],
    ]))['status'])->toBe('failed')
        ->and($reflection->invoke($page, ['status' => 'unknown'])['status'])->toBe('failed')
        ->and($reflection->invoke($page, ['status' => ['raw' => 'secret'], 'queue' => 'bad'])['issues'])->toContain('health_unavailable');
});

it('covers worker and scheduler records, stale reasons, and infrastructure thresholds', function (): void {
    config()->set(['queue.default' => 'database', 'cache.default' => 'database', 'processes.worker_count' => 2]);
    $writer = app(ProcessHeartbeatWriter::class);
    $writer->recordWorkerStarting('database', 'inbound,attachment-scans', 'worker-one');
    $writer->recordWorkerStarting('database', 'default', 'worker-two');
    $writer->recordSchedulerFailed(new RuntimeException('secret stack trace'), 'scheduler-one');
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get('/admin/process-health')
        ->assertOk()
        ->assertSee('database')
        ->assertSee('inbound, attachment-scans')
        ->assertSee('Status: failed')
        ->assertDontSee('worker-one')
        ->assertDontSee('worker-two')
        ->assertDontSee('scheduler-one')
        ->assertDontSee('secret stack trace')
        ->assertDontSee('process.heartbeat')
        ->assertDontSee('Authorization');
});

it('keeps the page read-only and exposes no mutation routes or actions', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin)->get('/admin/process-health')->assertOk()->assertDontSee('Create')->assertDontSee('Delete')->assertDontSee('Edit');
    $names = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter()->all();
    expect($names)->toContain('filament.admin.pages.process-health')
        ->and($names)->not->toContain('filament.admin.pages.process-health.create')
        ->and($names)->not->toContain('filament.admin.pages.process-health.edit');
});
