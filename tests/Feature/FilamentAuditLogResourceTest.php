<?php

use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Admin\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function createAuditLogForTest(
    User $actor,
    User $subject,
    array $overrides = [],
): AuditLog {
    /** @var AuditLogWriter $writer */
    $writer = app(AuditLogWriter::class);

    return $writer->write(
        action: $overrides['action'] ?? 'user.status_changed',
        actorUserId: (string) $actor->getKey(),
        auditable: $subject,
        oldValues: $overrides['old_values'] ?? ['status' => 'active'],
        newValues: $overrides['new_values'] ?? ['status' => 'suspended'],
        metadata: $overrides['metadata'] ?? [
            'target_user_id' => (string) $subject->getKey(),
            'changed_at' => now()->toIso8601String(),
        ],
        occurredAt: $overrides['created_at'] ?? now(),
        ipAddress: $overrides['ip_address'] ?? '203.0.113.10',
        userAgent: $overrides['user_agent'] ?? 'AuditLogResourceTest/1.0',
    );
}

it('allows an active admin to list and view audit logs', function (): void {
    $admin = User::factory()->platformAdmin()->create([
        'name' => 'Platform Admin',
        'email' => 'admin@example.test',
    ]);
    $subject = User::factory()->create([
        'name' => 'Subject User',
        'email' => 'subject@example.test',
    ]);
    $log = createAuditLogForTest($admin, $subject);

    $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('index'))
        ->assertOk()
        ->assertSee('user.status_changed')
        ->assertSee('Platform Admin')
        ->assertSee('admin@example.test');

    $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('view', ['record' => $log]))
        ->assertOk()
        ->assertSee('user.status_changed')
        ->assertSee('Platform Admin')
        ->assertSee('admin@example.test')
        ->assertSee('User')
        ->assertSee((string) $subject->getKey())
        ->assertSee('203.0.113.10')
        ->assertSee('AuditLogResourceTest/1.0');

    expect(AuditLogResource::canViewAny())->toBeTrue()
        ->and(AuditLogResource::canView($log))->toBeTrue()
        ->and(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit($log))->toBeFalse()
        ->and(AuditLogResource::canDelete($log))->toBeFalse();
});

it('hides navigation and denies direct URL access for operators', function (): void {
    $operator = User::factory()->platformOperator()->create();
    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create();
    $log = createAuditLogForTest($admin, $subject);

    $this->actingAs($operator);

    expect(AuditLogResource::canViewAny())->toBeFalse()
        ->and(AuditLogResource::shouldRegisterNavigation())->toBeFalse();

    $this->get(AuditLogResource::getUrl('index'))->assertForbidden();
    $this->get(AuditLogResource::getUrl('view', ['record' => $log]))->assertForbidden();

    $this->get('/admin')->assertOk();
});

it('denies ordinary users', function (): void {
    $user = User::factory()->create();
    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create();
    $log = createAuditLogForTest($admin, $subject);

    $this->actingAs($user)
        ->get(AuditLogResource::getUrl('index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(AuditLogResource::getUrl('view', ['record' => $log]))
        ->assertForbidden();
});

it('does not register create or edit routes', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->values()
        ->all();

    expect($routes)->not->toContain('filament.admin.resources.audit-logs.create')
        ->and($routes)->not->toContain('filament.admin.resources.audit-logs.edit')
        ->and($routes)->toContain('filament.admin.resources.audit-logs.index')
        ->and($routes)->toContain('filament.admin.resources.audit-logs.view');

    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create();
    $log = createAuditLogForTest($admin, $subject);

    $this->actingAs($admin)
        ->get('/admin/audit-logs/create')
        ->assertNotFound();

    $this->actingAs($admin)
        ->get("/admin/audit-logs/{$log->id}/edit")
        ->assertNotFound();
});

it('is read-only with no create edit or delete actions on list and view pages', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create();
    $log = createAuditLogForTest($admin, $subject);

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->assertSuccessful()
        ->assertActionDoesNotExist('create')
        ->assertTableActionExists('view')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::actingAs($admin)
        ->test(ViewAuditLog::class, ['record' => $log->getKey()])
        ->assertSuccessful()
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('create');
});

it('displays audit event fields with actor and subject', function (): void {
    $admin = User::factory()->platformAdmin()->create([
        'name' => 'Audit Actor',
        'email' => 'audit-actor@example.test',
    ]);
    $subject = User::factory()->create([
        'name' => 'Audit Subject',
        'email' => 'audit-subject@example.test',
    ]);
    $log = createAuditLogForTest($admin, $subject, [
        'action' => 'user.platform_role_changed',
        'old_values' => ['platform_role' => 'user'],
        'new_values' => ['platform_role' => 'operator'],
        'metadata' => [
            'target_user_id' => (string) $subject->getKey(),
            'revoked_key_count' => 0,
            'changed_at' => '2026-07-22T12:00:00+00:00',
        ],
    ]);

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$log])
        ->assertSee('user.platform_role_changed')
        ->assertSee('Audit Actor')
        ->assertSee('audit-actor@example.test')
        ->assertSee((string) $subject->getKey());

    $view = $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('view', ['record' => $log]));

    $view->assertOk()
        ->assertSee('user.platform_role_changed')
        ->assertSee('Audit Actor')
        ->assertSee('audit-actor@example.test')
        ->assertSee('User')
        ->assertSee((string) $subject->getKey())
        ->assertSee('platform_role')
        ->assertSee('operator')
        ->assertSee('target_user_id')
        ->assertSee('revoked_key_count');
});

it('does not display sensitive keys or values from nested payloads', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create();

    $sensitiveValues = [
        'password' => 'super-secret-password',
        'password_confirmation' => 'super-secret-password',
        'remember_token' => 'sensitive-remember-token',
        'token' => 'sensitive-bearer-token',
        'plain_text_token' => 'te_live_sensitive_plain_token',
        'key_hash' => 'sensitive-key-hash-value',
        'api_key' => 'sensitive-api-key-value',
        'secret' => 'sensitive-secret-value',
        'authorization' => 'Bearer sensitive-auth-header',
        'cookie' => 'session=sensitive-cookie-value',
    ];

    $log = createAuditLogForTest($admin, $subject, [
        'old_values' => array_merge(['status' => 'active'], $sensitiveValues),
        'new_values' => array_merge(['status' => 'suspended'], $sensitiveValues),
        'metadata' => [
            'safe_note' => 'visible-metadata-note',
            'nested' => $sensitiveValues,
            'token' => 'top-level-sensitive-token',
        ],
    ]);

    $list = $this->actingAs($admin)->get(AuditLogResource::getUrl('index'));
    $view = $this->actingAs($admin)->get(AuditLogResource::getUrl('view', ['record' => $log]));

    foreach ([$list, $view] as $response) {
        $response->assertOk()
            ->assertDontSee('super-secret-password')
            ->assertDontSee('sensitive-remember-token')
            ->assertDontSee('sensitive-bearer-token')
            ->assertDontSee('te_live_sensitive_plain_token')
            ->assertDontSee('sensitive-key-hash-value')
            ->assertDontSee('sensitive-api-key-value')
            ->assertDontSee('sensitive-secret-value')
            ->assertDontSee('Bearer sensitive-auth-header')
            ->assertDontSee('sensitive-cookie-value')
            ->assertDontSee('top-level-sensitive-token');
    }

    $view->assertDontSee('visible-metadata-note')
        ->assertDontSee('safe_note')
        ->assertSee('status')
        ->assertSee('suspended');
});

it('renders json metadata safely without raw html', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create();
    $log = createAuditLogForTest($admin, $subject, [
        'metadata' => [
            'note' => '<script>alert("xss")</script>',
            'context' => ['reason' => 'role change'],
        ],
    ]);

    $response = $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('view', ['record' => $log]));

    $response->assertOk()
        ->assertDontSee('role change')
        ->assertDontSee('<script>alert("xss")</script>', false)
        ->assertDontSee('xss');
});

it('does not mutate existing audit records when viewing', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create();
    $log = createAuditLogForTest($admin, $subject, [
        'metadata' => [
            'target_user_id' => (string) $subject->getKey(),
            'token' => 'must-remain-in-storage',
        ],
    ]);

    $original = $log->fresh()->only([
        'action',
        'user_id',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'old_values',
        'new_values',
        'metadata',
        'created_at',
    ]);

    $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('view', ['record' => $log]))
        ->assertOk();

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->assertSuccessful();

    $fresh = $log->fresh()->only([
        'action',
        'user_id',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'old_values',
        'new_values',
        'metadata',
        'created_at',
    ]);

    expect($fresh)->toEqual($original)
        ->and(AuditLog::query()->count())->toBe(1)
        ->and($log->fresh()->metadata['token'] ?? null)->toBeNull();
});

it('searches by event and actor email and supports filters', function (): void {
    $admin = User::factory()->platformAdmin()->create([
        'name' => 'Filter Admin',
        'email' => 'filter-admin@example.test',
    ]);
    $otherActor = User::factory()->platformAdmin()->create([
        'name' => 'Other Actor',
        'email' => 'other-actor@example.test',
    ]);
    $subject = User::factory()->create();

    $statusLog = createAuditLogForTest($admin, $subject, [
        'action' => 'user.status_changed',
        'created_at' => now()->subDays(2),
    ]);
    $roleLog = createAuditLogForTest($otherActor, $subject, [
        'action' => 'user.platform_role_changed',
        'old_values' => ['platform_role' => 'user'],
        'new_values' => ['platform_role' => 'operator'],
        'created_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$statusLog, $roleLog])
        ->set('tableSearch', 'platform_role_changed')
        ->assertCanSeeTableRecords([$roleLog])
        ->assertCanNotSeeTableRecords([$statusLog])
        ->set('tableSearch', 'other-actor@example.test')
        ->assertCanSeeTableRecords([$roleLog])
        ->assertCanNotSeeTableRecords([$statusLog])
        ->set('tableSearch', '')
        ->filterTable('action', 'user.status_changed')
        ->assertCanSeeTableRecords([$statusLog])
        ->assertCanNotSeeTableRecords([$roleLog])
        ->resetTableFilters()
        ->filterTable('user_id', (string) $otherActor->getKey())
        ->assertCanSeeTableRecords([$roleLog])
        ->assertCanNotSeeTableRecords([$statusLog])
        ->resetTableFilters()
        ->filterTable('created_at', [
            'created_from' => now()->subDay()->toDateString(),
            'created_until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$roleLog])
        ->assertCanNotSeeTableRecords([$statusLog]);
});

it('paginates audit log records', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create();

    for ($i = 0; $i < 15; $i++) {
        createAuditLogForTest($admin, $subject, [
            'action' => 'user.status_changed',
            'created_at' => now()->subMinutes($i),
        ]);
    }

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->assertSuccessful()
        ->assertCountTableRecords(15);
});
