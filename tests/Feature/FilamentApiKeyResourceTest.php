<?php

use App\Actions\ApiKey\RevokeApiKeyAction;
use App\Enums\ApiKeyScope;
use App\Enums\UserStatus;
use App\Filament\Admin\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Admin\Resources\ApiKeys\Pages\ListApiKeys;
use App\Filament\Admin\Resources\ApiKeys\Pages\ViewApiKey;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'filament-api-key-resource-test-secret']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * @param  list<string>|null  $permissions
 * @return array{api_key: ApiKey, plain_token: string, key_hash: string}
 */
function createApiKeyForFilamentTest(User $owner, ?array $permissions = null, array $overrides = []): array
{
    $credentials = app(ApiKeyTokenGenerator::class)->generate();

    $apiKey = ApiKey::query()->create(array_merge([
        'user_id' => $owner->id,
        'name' => 'filament-key-'.uniqid(),
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => $permissions ?? [ApiKeyScope::InboxesRead->value],
        'rate_limit_per_minute' => 60,
    ], $overrides));

    return [
        'api_key' => $apiKey->fresh(),
        'plain_token' => $credentials['plain_token'],
        'key_hash' => $credentials['key_hash'],
    ];
}

it('allows an active admin to list and view api keys', function (): void {
    $admin = User::factory()->platformAdmin()->create([
        'name' => 'Platform Admin',
        'email' => 'admin@example.test',
    ]);
    $owner = User::factory()->create([
        'name' => 'Key Owner',
        'email' => 'key-owner@example.test',
    ]);
    $created = createApiKeyForFilamentTest($owner, [ApiKeyScope::InboxesRead->value], [
        'name' => 'Owner Inbox Key',
    ]);
    $apiKey = $created['api_key'];

    $this->actingAs($admin)
        ->get(ApiKeyResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Owner Inbox Key')
        ->assertSee('Key Owner')
        ->assertSee('key-owner@example.test')
        ->assertDontSee($created['key_hash'])
        ->assertDontSee($created['plain_token']);

    $this->actingAs($admin)
        ->get(ApiKeyResource::getUrl('view', ['record' => $apiKey]))
        ->assertOk()
        ->assertSee('Owner Inbox Key')
        ->assertSee('Key Owner')
        ->assertSee('key-owner@example.test')
        ->assertSee(ApiKeyScope::InboxesRead->value)
        ->assertDontSee($created['key_hash'])
        ->assertDontSee($created['plain_token']);

    expect(ApiKeyResource::canViewAny())->toBeTrue()
        ->and(ApiKeyResource::canView($apiKey))->toBeTrue()
        ->and(ApiKeyResource::canCreate())->toBeFalse()
        ->and(ApiKeyResource::canEdit($apiKey))->toBeFalse()
        ->and(ApiKeyResource::canDelete($apiKey))->toBeFalse();
});

it('hides navigation and denies direct URL access for operators', function (): void {
    $operator = User::factory()->platformOperator()->create();
    $owner = User::factory()->create();
    $apiKey = createApiKeyForFilamentTest($owner)['api_key'];

    $this->actingAs($operator);

    expect(ApiKeyResource::canViewAny())->toBeFalse()
        ->and(ApiKeyResource::shouldRegisterNavigation())->toBeFalse();

    $this->get(ApiKeyResource::getUrl('index'))->assertForbidden();
    $this->get(ApiKeyResource::getUrl('view', ['record' => $apiKey]))->assertForbidden();

    $this->get('/admin')->assertOk();
});

it('denies ordinary users', function (): void {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $apiKey = createApiKeyForFilamentTest($owner)['api_key'];

    $this->actingAs($user)
        ->get(ApiKeyResource::getUrl('index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(ApiKeyResource::getUrl('view', ['record' => $apiKey]))
        ->assertForbidden();
});

it('does not register create or edit routes', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->values()
        ->all();

    expect($routes)->not->toContain('filament.admin.resources.api-keys.create')
        ->and($routes)->not->toContain('filament.admin.resources.api-keys.edit')
        ->and($routes)->toContain('filament.admin.resources.api-keys.index')
        ->and($routes)->toContain('filament.admin.resources.api-keys.view');

    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();
    $apiKey = createApiKeyForFilamentTest($owner)['api_key'];

    $this->actingAs($admin)
        ->get('/admin/api-keys/create')
        ->assertNotFound();

    $this->actingAs($admin)
        ->get("/admin/api-keys/{$apiKey->id}/edit")
        ->assertNotFound();
});

it('is read-only with no create edit or delete actions on list and view pages', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();
    $apiKey = createApiKeyForFilamentTest($owner)['api_key'];

    Livewire::actingAs($admin)
        ->test(ListApiKeys::class)
        ->assertSuccessful()
        ->assertActionDoesNotExist('create')
        ->assertTableActionExists('view')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::actingAs($admin)
        ->test(ViewApiKey::class, ['record' => $apiKey->getKey()])
        ->assertSuccessful()
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('create')
        ->assertActionVisible('revoke');
});

it('displays owner scopes and active revoked expired statuses correctly', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00'));

    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create([
        'name' => 'Status Owner',
        'email' => 'status-owner@example.test',
    ]);

    $active = createApiKeyForFilamentTest($owner, [ApiKeyScope::InboxesRead->value], [
        'name' => 'Active Key',
        'expires_at' => now()->addDay(),
    ])['api_key'];

    $revoked = createApiKeyForFilamentTest($owner, [ApiKeyScope::InboxesWrite->value], [
        'name' => 'Revoked Key',
        'revoked_at' => now()->subHour(),
        'expires_at' => now()->subDay(),
    ])['api_key'];

    $expired = createApiKeyForFilamentTest($owner, [ApiKeyScope::MailServersRead->value], [
        'name' => 'Expired Key',
        'expires_at' => now()->subHour(),
    ])['api_key'];

    expect(ApiKeyResource::resolveStatus($active))->toBe('active')
        ->and(ApiKeyResource::resolveStatus($revoked))->toBe('revoked')
        ->and(ApiKeyResource::resolveStatus($expired))->toBe('expired');

    Livewire::actingAs($admin)
        ->test(ListApiKeys::class)
        ->assertCanSeeTableRecords([$active, $revoked, $expired])
        ->assertSee('Active Key')
        ->assertSee('Revoked Key')
        ->assertSee('Expired Key')
        ->assertSee('Status Owner')
        ->assertSee(ApiKeyScope::InboxesRead->value)
        ->assertSee(ApiKeyScope::InboxesWrite->value)
        ->assertSee(ApiKeyScope::MailServersRead->value)
        ->assertSee('active')
        ->assertSee('revoked')
        ->assertSee('expired');

    $this->actingAs($admin)
        ->get(ApiKeyResource::getUrl('view', ['record' => $revoked]))
        ->assertOk()
        ->assertSee('Revoked Key')
        ->assertSee('status-owner@example.test')
        ->assertSee(ApiKeyScope::InboxesWrite->value)
        ->assertSee('revoked');

    Carbon::setTestNow();
});

it('searches by key name and owner and supports filters', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00'));

    $admin = User::factory()->platformAdmin()->create();
    $alice = User::factory()->create([
        'name' => 'Alice Owner',
        'email' => 'alice-owner@example.test',
    ]);
    $bob = User::factory()->create([
        'name' => 'Bob Owner',
        'email' => 'bob-owner@example.test',
    ]);

    $aliceActive = createApiKeyForFilamentTest($alice, [ApiKeyScope::InboxesRead->value], [
        'name' => 'Alice Active Key',
        'expires_at' => now()->addDay(),
    ])['api_key'];
    $aliceRevoked = createApiKeyForFilamentTest($alice, [ApiKeyScope::InboxesWrite->value], [
        'name' => 'Alice Revoked Key',
        'revoked_at' => now()->subHour(),
    ])['api_key'];
    $bobExpired = createApiKeyForFilamentTest($bob, [ApiKeyScope::MailServersRead->value], [
        'name' => 'Bob Expired Key',
        'expires_at' => now()->subHour(),
    ])['api_key'];

    Livewire::actingAs($admin)
        ->test(ListApiKeys::class)
        ->assertCanSeeTableRecords([$aliceActive, $aliceRevoked, $bobExpired])
        ->set('tableSearch', 'Alice Active Key')
        ->assertCanSeeTableRecords([$aliceActive])
        ->assertCanNotSeeTableRecords([$aliceRevoked, $bobExpired])
        ->set('tableSearch', 'bob-owner@example.test')
        ->assertCanSeeTableRecords([$bobExpired])
        ->assertCanNotSeeTableRecords([$aliceActive, $aliceRevoked])
        ->set('tableSearch', '')
        ->filterTable('revoked', true)
        ->assertCanSeeTableRecords([$aliceRevoked])
        ->assertCanNotSeeTableRecords([$aliceActive, $bobExpired])
        ->resetTableFilters()
        ->filterTable('lifecycle', 'expired')
        ->assertCanSeeTableRecords([$bobExpired])
        ->assertCanNotSeeTableRecords([$aliceActive, $aliceRevoked])
        ->resetTableFilters()
        ->filterTable('lifecycle', 'active')
        ->assertCanSeeTableRecords([$aliceActive])
        ->assertCanNotSeeTableRecords([$aliceRevoked, $bobExpired])
        ->resetTableFilters()
        ->filterTable('user_id', (string) $bob->getKey())
        ->assertCanSeeTableRecords([$bobExpired])
        ->assertCanNotSeeTableRecords([$aliceActive, $aliceRevoked])
        ->resetTableFilters()
        ->filterTable('scope', ApiKeyScope::MailServersRead->value)
        ->assertCanSeeTableRecords([$bobExpired])
        ->assertCanNotSeeTableRecords([$aliceActive, $aliceRevoked]);

    Carbon::setTestNow();
});

it('does not expose key hash plaintext token or sensitive metadata', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create([
        'remember_token' => 'sensitive-owner-remember-token',
    ]);

    $created = createApiKeyForFilamentTest($owner, [ApiKeyScope::InboxesRead->value], [
        'name' => 'Sensitive Metadata Key',
        'metadata' => [
            'safe_note' => 'visible-usage-note',
            'token' => 'metadata-sensitive-token',
            'plain_text_token' => 'metadata-plain-text-token',
            'key' => 'metadata-key-value',
            'key_hash' => 'metadata-key-hash-value',
            'secret' => 'metadata-secret-value',
            'authorization' => 'Bearer metadata-auth',
            'password' => 'metadata-password-value',
            'remember_token' => 'metadata-remember-token',
            'nested' => [
                'key_hash' => 'nested-key-hash-value',
                'note' => 'nested-safe-note',
            ],
        ],
    ]);
    $apiKey = $created['api_key'];

    $list = $this->actingAs($admin)->get(ApiKeyResource::getUrl('index'));
    $view = $this->actingAs($admin)->get(ApiKeyResource::getUrl('view', ['record' => $apiKey]));

    foreach ([$list, $view] as $response) {
        $response->assertOk()
            ->assertDontSee($created['key_hash'])
            ->assertDontSee($created['plain_token'])
            ->assertDontSee('metadata-sensitive-token')
            ->assertDontSee('metadata-plain-text-token')
            ->assertDontSee('metadata-key-value')
            ->assertDontSee('metadata-key-hash-value')
            ->assertDontSee('metadata-secret-value')
            ->assertDontSee('Bearer metadata-auth')
            ->assertDontSee('metadata-password-value')
            ->assertDontSee('metadata-remember-token')
            ->assertDontSee('nested-key-hash-value')
            ->assertDontSee('sensitive-owner-remember-token');
    }

    $list->assertDontSee($apiKey->key_prefix);

    $view->assertSee('visible-usage-note')
        ->assertSee('nested-safe-note')
        ->assertSee('safe_note');
});

it('does not mutate existing api key records when viewing', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();
    $created = createApiKeyForFilamentTest($owner, [ApiKeyScope::InboxesRead->value], [
        'name' => 'Immutable Key',
        'metadata' => [
            'safe_note' => 'keep-me',
            'token' => 'must-remain-in-storage',
        ],
        'last_used_at' => now()->subDay(),
        'expires_at' => now()->addDays(3),
    ]);
    $apiKey = $created['api_key'];

    $original = $apiKey->fresh()->only([
        'user_id',
        'name',
        'key_prefix',
        'key_hash',
        'permissions',
        'rate_limit_per_minute',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'metadata',
        'created_at',
        'updated_at',
    ]);

    $this->actingAs($admin)
        ->get(ApiKeyResource::getUrl('view', ['record' => $apiKey]))
        ->assertOk();

    Livewire::actingAs($admin)
        ->test(ListApiKeys::class)
        ->assertSuccessful();

    $fresh = $apiKey->fresh()->only([
        'user_id',
        'name',
        'key_prefix',
        'key_hash',
        'permissions',
        'rate_limit_per_minute',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'metadata',
        'created_at',
        'updated_at',
    ]);

    expect($fresh)->toEqual($original)
        ->and(ApiKey::query()->count())->toBe(1)
        ->and($apiKey->fresh()->metadata['token'] ?? null)->toBe('must-remain-in-storage');
});

it('paginates api key records', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();

    for ($i = 0; $i < 15; $i++) {
        createApiKeyForFilamentTest($owner, [ApiKeyScope::InboxesRead->value], [
            'name' => "Paged Key {$i}",
        ]);
    }

    Livewire::actingAs($admin)
        ->test(ListApiKeys::class)
        ->assertSuccessful()
        ->assertCountTableRecords(15);
});

it('allows an admin to revoke an api key through the header action', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-22 15:30:00'));

    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();
    $created = createApiKeyForFilamentTest($owner, [
        ApiKeyScope::InboxesRead->value,
        ApiKeyScope::InboxesWrite->value,
    ], [
        'name' => 'Revocable Key',
    ]);
    $apiKey = $created['api_key'];
    $permissionsBefore = $apiKey->permissions;

    $component = Livewire::actingAs($admin)
        ->test(ViewApiKey::class, ['record' => $apiKey->getKey()])
        ->assertActionVisible('revoke')
        ->callAction('revoke')
        ->assertSee('revoked')
        ->assertDontSee($created['key_hash'])
        ->assertDontSee($created['plain_token']);

    $component->assertSuccessful();

    $fresh = $apiKey->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->revoked_at?->equalTo(Carbon::parse('2026-07-22 15:30:00')))->toBeTrue()
        ->and($fresh->permissions)->toBe($permissionsBefore)
        ->and(ApiKeyResource::resolveStatus($fresh))->toBe('revoked')
        ->and(ApiKey::query()->count())->toBe(1);

    $audit = AuditLog::query()->where('action', RevokeApiKeyAction::AUDIT_ACTION)->sole();
    expect($audit->user_id)->toBe((string) $admin->getKey())
        ->and($audit->auditable_type)->toBe(ApiKey::class)
        ->and($audit->auditable_id)->toBe((string) $apiKey->getKey())
        ->and($audit->old_values)->toBe(['revoked_at' => null])
        ->and($audit->new_values['revoked_at'])->toBe(Carbon::parse('2026-07-22 15:30:00')->toIso8601String())
        ->and($audit->metadata)->toBe([
            'target_api_key_id' => (string) $apiKey->getKey(),
            'owner_user_id' => (string) $owner->getKey(),
            'source' => 'filament',
        ])
        ->and(json_encode($audit->toArray()))->not->toContain($created['plain_token'])
        ->and(json_encode($audit->toArray()))->not->toContain($created['key_hash']);

    Livewire::actingAs($admin)
        ->test(ViewApiKey::class, ['record' => $apiKey->getKey()])
        ->assertActionHidden('revoke');

    Carbon::setTestNow();
});

it('hides the revoke action for already-revoked keys and keeps expired keys revocable', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-22 15:30:00'));

    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();

    $revoked = createApiKeyForFilamentTest($owner, [ApiKeyScope::InboxesRead->value], [
        'name' => 'Already Revoked Key',
        'revoked_at' => now()->subHour(),
    ])['api_key'];

    $expired = createApiKeyForFilamentTest($owner, [ApiKeyScope::InboxesWrite->value], [
        'name' => 'Expired Still Revocable',
        'expires_at' => now()->subHour(),
    ])['api_key'];

    Livewire::actingAs($admin)
        ->test(ViewApiKey::class, ['record' => $revoked->getKey()])
        ->assertSuccessful()
        ->assertActionHidden('revoke');

    Livewire::actingAs($admin)
        ->test(ViewApiKey::class, ['record' => $expired->getKey()])
        ->assertActionVisible('revoke')
        ->callAction('revoke');

    $freshExpired = $expired->fresh();

    expect($freshExpired->revoked_at?->equalTo(Carbon::parse('2026-07-22 15:30:00')))->toBeTrue()
        ->and(ApiKeyResource::resolveStatus($freshExpired))->toBe('revoked')
        ->and($freshExpired->permissions)->toBe([ApiKeyScope::InboxesWrite->value])
        ->and(AuditLog::query()->where('action', RevokeApiKeyAction::AUDIT_ACTION)->count())->toBe(1)
        ->and(AuditLog::query()->where('auditable_id', $revoked->getKey())->exists())->toBeFalse();

    Carbon::setTestNow();
});

it('denies revoke visibility and unauthorized access for operators users and suspended admins', function (): void {
    $owner = User::factory()->create();
    $created = createApiKeyForFilamentTest($owner, [ApiKeyScope::InboxesRead->value], [
        'name' => 'Protected Key',
    ]);
    $apiKey = $created['api_key'];

    $operator = User::factory()->platformOperator()->create();
    $user = User::factory()->create();
    $suspendedAdmin = User::factory()->platformAdmin()->create([
        'status' => UserStatus::Suspended,
    ]);

    $this->actingAs($operator)
        ->get(ApiKeyResource::getUrl('view', ['record' => $apiKey]))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(ApiKeyResource::getUrl('view', ['record' => $apiKey]))
        ->assertForbidden();

    $this->actingAs($suspendedAdmin)
        ->get(ApiKeyResource::getUrl('view', ['record' => $apiKey]))
        ->assertForbidden();

    expect($apiKey->fresh()->revoked_at)->toBeNull();
});

it('does not expose plaintext token or hash during revoke confirmation or after revoke', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();
    $created = createApiKeyForFilamentTest($owner, [ApiKeyScope::InboxesRead->value], [
        'name' => 'Safe Revoke Key',
    ]);
    $apiKey = $created['api_key'];

    $component = Livewire::actingAs($admin)
        ->test(ViewApiKey::class, ['record' => $apiKey->getKey()])
        ->assertActionVisible('revoke')
        ->mountAction('revoke')
        ->assertSee('Safe Revoke Key')
        ->assertDontSee($created['key_hash'])
        ->assertDontSee($created['plain_token']);

    $component->callMountedAction()
        ->assertDontSee($created['key_hash'])
        ->assertDontSee($created['plain_token']);

    expect($apiKey->fresh()->revoked_at)->not->toBeNull()
        ->and($created['plain_token'])->toStartWith($apiKey->key_prefix)
        ->and(strlen($created['plain_token']))->toBeGreaterThan(strlen($apiKey->key_prefix));

    $audit = AuditLog::query()->where('action', RevokeApiKeyAction::AUDIT_ACTION)->sole();
    expect(json_encode($audit->toArray()))->not->toContain($created['plain_token'])
        ->and(json_encode($audit->toArray()))->not->toContain($created['key_hash'])
        ->and($audit->metadata['source'] ?? null)->toBe('filament');
});
