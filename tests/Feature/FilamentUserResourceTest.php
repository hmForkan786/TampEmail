<?php

use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'filament-user-resource-test-secret']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('allows an active admin to list and view users', function (): void {
    $admin = User::factory()->platformAdmin()->create([
        'name' => 'Platform Admin',
        'email' => 'admin@example.test',
    ]);
    $subject = User::factory()->create([
        'name' => 'Subject User',
        'email' => 'subject@example.test',
    ]);

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Subject User')
        ->assertSee('subject@example.test')
        ->assertDontSee($admin->password)
        ->assertDontSee('remember_token');

    $this->actingAs($admin)
        ->get(UserResource::getUrl('view', ['record' => $subject]))
        ->assertOk()
        ->assertSee('Subject User')
        ->assertSee('subject@example.test')
        ->assertSee('User')
        ->assertSee('Active')
        ->assertDontSee('key_hash')
        ->assertDontSee('te_live_');

    expect(UserResource::canViewAny())->toBeTrue()
        ->and(UserResource::canView($subject))->toBeTrue()
        ->and(UserResource::canCreate())->toBeFalse()
        ->and(UserResource::canEdit($subject))->toBeFalse()
        ->and(UserResource::canDelete($subject))->toBeFalse();
});

it('hides navigation and denies direct URL access for operators', function (): void {
    $operator = User::factory()->platformOperator()->create();
    $subject = User::factory()->create();

    $this->actingAs($operator);

    expect(UserResource::canViewAny())->toBeFalse()
        ->and(UserResource::shouldRegisterNavigation())->toBeFalse();

    $this->get(UserResource::getUrl('index'))->assertForbidden();
    $this->get(UserResource::getUrl('view', ['record' => $subject]))->assertForbidden();

    // Operators can still reach the panel dashboard, but the Users resource is not registered.
    $this->get('/admin')->assertOk();
});

it('denies ordinary users', function (): void {
    $user = User::factory()->create();
    $subject = User::factory()->create();

    $this->actingAs($user)
        ->get(UserResource::getUrl('index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(UserResource::getUrl('view', ['record' => $subject]))
        ->assertForbidden();
});

it('denies suspended and banned admins', function (UserStatus $status): void {
    $admin = User::factory()->platformAdmin()->create(['status' => $status]);
    $subject = User::factory()->create();

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(UserResource::getUrl('view', ['record' => $subject]))
        ->assertForbidden();
})->with([
    UserStatus::Suspended,
    UserStatus::Banned,
]);

it('does not register create or edit routes', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->values()
        ->all();

    expect($routes)->not->toContain('filament.admin.resources.users.create')
        ->and($routes)->not->toContain('filament.admin.resources.users.edit')
        ->and($routes)->toContain('filament.admin.resources.users.index')
        ->and($routes)->toContain('filament.admin.resources.users.view');

    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create();

    $this->actingAs($admin)
        ->get('/admin/users/create')
        ->assertNotFound();

    $this->actingAs($admin)
        ->get("/admin/users/{$subject->id}/edit")
        ->assertNotFound();
});

it('is read-only with no create edit or delete actions on list and view pages', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertSuccessful()
        ->assertActionDoesNotExist('create')
        ->assertTableActionExists('view')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::actingAs($admin)
        ->test(ViewUser::class, ['record' => $subject->getKey()])
        ->assertSuccessful()
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete');
});

it('allows only platform admins to see the user mutation actions', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(ViewUser::class, ['record' => $user->getKey()])
        ->assertActionVisible('changeStatus')
        ->assertActionVisible('changePlatformRole');

    $this->actingAs($user)
        ->get(UserResource::getUrl('view', ['record' => $user]))
        ->assertForbidden();
});

it('changes status through the audited Filament action and ignores the same status', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create(['status' => UserStatus::Active]);

    Livewire::actingAs($admin)
        ->test(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('changeStatus', ['status' => UserStatus::Suspended->value]);

    expect($user->fresh()->status)->toBe(UserStatus::Suspended);
    $audit = AuditLog::query()->where('action', 'user.status_changed')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe((string) $admin->getKey())
        ->and($audit->auditable_id)->toBe((string) $user->getKey());

    Livewire::actingAs($admin)
        ->test(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('changeStatus', ['status' => UserStatus::Suspended->value]);

    expect($user->fresh()->status)->toBe(UserStatus::Suspended)
        ->and(AuditLog::query()->where('action', 'user.status_changed')->count())->toBe(1);
});

it('does not mutate status when an unauthorized user attempts the action', function (): void {
    $user = User::factory()->create(['status' => UserStatus::Active]);

    $this->actingAs($user)
        ->get(UserResource::getUrl('view', ['record' => $user]))
        ->assertForbidden();

    expect($user->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::query()->where('action', 'user.status_changed')->exists())->toBeFalse();
});

it('rejects an invalid status value without mutating the user', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create(['status' => UserStatus::Active]);

    Livewire::actingAs($admin)
        ->test(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('changeStatus', ['status' => 'invalid-status'])
        ->assertHasErrors();

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

it('changes platform role through the audited Filament action and ignores the same role', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create(['platform_role' => PlatformRole::User]);

    Livewire::actingAs($admin)
        ->test(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('changePlatformRole', ['platform_role' => PlatformRole::Operator->value]);

    expect($user->fresh()->platform_role)->toBe(PlatformRole::Operator);
    $audit = AuditLog::query()->where('action', 'user.platform_role_changed')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe((string) $admin->getKey())
        ->and($audit->auditable_id)->toBe((string) $user->getKey());

    Livewire::actingAs($admin)
        ->test(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('changePlatformRole', ['platform_role' => PlatformRole::Operator->value]);

    expect($user->fresh()->platform_role)->toBe(PlatformRole::Operator)
        ->and(AuditLog::query()->where('action', 'user.platform_role_changed')->count())->toBe(1);
});

it('does not mutate platform role when an unauthorized user attempts the action', function (): void {
    $user = User::factory()->create(['platform_role' => PlatformRole::User]);

    $this->actingAs($user)
        ->get(UserResource::getUrl('view', ['record' => $user]))
        ->assertForbidden();

    expect($user->fresh()->platform_role)->toBe(PlatformRole::User)
        ->and(AuditLog::query()->where('action', 'user.platform_role_changed')->exists())->toBeFalse();
});

it('rejects an invalid platform role value without mutating the user', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create(['platform_role' => PlatformRole::User]);

    Livewire::actingAs($admin)
        ->test(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('changePlatformRole', ['platform_role' => 'invalid-role'])
        ->assertHasErrors();

    expect($user->fresh()->platform_role)->toBe(PlatformRole::User);
});

it('filters users by platform role and status', function (): void {
    $admin = User::factory()->platformAdmin()->create([
        'email' => 'filter-admin@example.test',
    ]);
    $operator = User::factory()->platformOperator()->create([
        'email' => 'filter-operator@example.test',
    ]);
    $suspended = User::factory()->create([
        'email' => 'filter-suspended@example.test',
        'status' => UserStatus::Suspended,
    ]);

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertCanSeeTableRecords([$admin, $operator, $suspended])
        ->filterTable('platform_role', PlatformRole::Operator->value)
        ->assertCanSeeTableRecords([$operator])
        ->assertCanNotSeeTableRecords([$admin, $suspended])
        ->resetTableFilters()
        ->filterTable('status', UserStatus::Suspended->value)
        ->assertCanSeeTableRecords([$suspended])
        ->assertCanNotSeeTableRecords([$admin, $operator]);
});

it('searches by name and email and paginates', function (): void {
    $admin = User::factory()->platformAdmin()->create([
        'name' => 'Search Admin',
        'email' => 'search-admin@example.test',
    ]);
    $alice = User::factory()->create([
        'name' => 'Alice Searchable',
        'email' => 'alice-search@example.test',
    ]);
    $bob = User::factory()->create([
        'name' => 'Bob Other',
        'email' => 'bob-other@example.test',
    ]);

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->set('tableSearch', 'Alice Searchable')
        ->assertCanSeeTableRecords([$alice])
        ->assertCanNotSeeTableRecords([$bob])
        ->set('tableSearch', 'bob-other@example.test')
        ->assertCanSeeTableRecords([$bob])
        ->assertCanNotSeeTableRecords([$alice]);

    User::factory()->count(20)->create();

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertSuccessful()
        ->assertCountTableRecords(23);
});

it('does not expose sensitive credentials on list or view pages', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $subject = User::factory()->create([
        'remember_token' => 'sensitive-remember-token-value',
    ]);
    $credentials = app(ApiKeyTokenGenerator::class)->generate();
    $subject->apiKeys()->create([
        'name' => 'secret-key',
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => ['inboxes:read'],
        'rate_limit_per_minute' => 60,
    ]);

    $list = $this->actingAs($admin)->get(UserResource::getUrl('index'));
    $view = $this->actingAs($admin)->get(UserResource::getUrl('view', ['record' => $subject]));

    foreach ([$list, $view] as $response) {
        $response->assertOk()
            ->assertDontSee('sensitive-remember-token-value')
            ->assertDontSee($credentials['key_hash'])
            ->assertDontSee($credentials['plain_token']);
    }
});

it('excludes soft-deleted users from the default list', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $active = User::factory()->create(['email' => 'active-listed@example.test']);
    $deleted = User::factory()->create(['email' => 'deleted-hidden@example.test']);
    $deleted->delete();

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$deleted]);

    $this->actingAs($admin)
        ->get(UserResource::getUrl('view', ['record' => $deleted]))
        ->assertNotFound();
});
