<?php

declare(strict_types=1);

use App\Enums\NotificationPreferenceCategory;
use App\Enums\NotificationPreferenceChannel;
use App\Enums\PrivacyExportStatus;
use App\Enums\UserStatus;
use App\Jobs\Settings\ProcessPrivacyExportJob;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\Identity\PasswordChangedNotification;
use App\Services\Identity\SessionManagementService;
use App\Services\Settings\NotificationPreferenceService;
use App\Services\Settings\PrivacyPreferenceService;
use App\Services\Settings\SettingsApiKeyService;
use App\Services\Settings\UserSettingsSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'session.driver' => 'database',
        'settings.password_change.revoke_other_sessions' => true,
        'settings.api_keys.require_password' => true,
        'settings.privacy.export.enabled' => true,
        'settings.avatar.enabled' => false,
        'identity.password.uncompromised_check' => false,
        'api.key_hash_secret' => 'settings-test-secret',
    ]);
    Notification::fake();
});

it('loads the settings dashboard for an active owner', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('SettingsPass1!'),
        'locale' => 'en',
        'timezone' => 'UTC',
    ]);

    $this->actingAs($user)
        ->get(route('settings.index'))
        ->assertOk()
        ->assertSee('Settings overview')
        ->assertSee('Settings', false);
});

it('blocks inactive users from settings', function (): void {
    $user = User::factory()->create([
        'status' => UserStatus::Suspended,
        'password' => Hash::make('SettingsPass1!'),
    ]);

    $this->actingAs($user)
        ->get(route('settings.index'))
        ->assertRedirect(route('login'));
});

it('updates profile and rejects email injection', function (): void {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'locale' => 'en',
        'timezone' => 'UTC',
        'password' => Hash::make('SettingsPass1!'),
    ]);

    $this->actingAs($user)
        ->put(route('settings.profile.update'), [
            'name' => 'New Name',
            'locale' => 'en',
            'timezone' => 'UTC',
            'email' => 'hijack@example.com',
        ])
        ->assertSessionHasErrors('email');

    $this->actingAs($user)
        ->put(route('settings.profile.update'), [
            'name' => 'New Name',
            'locale' => 'en',
            'timezone' => 'Asia/Dhaka',
        ])
        ->assertRedirect();

    expect($user->fresh()->name)->toBe('New Name')
        ->and($user->fresh()->timezone)->toBe('Asia/Dhaka')
        ->and($user->fresh()->email)->not->toBe('hijack@example.com');
});

it('rejects invalid locale and timezone', function (): void {
    $user = User::factory()->create([
        'locale' => 'en',
        'timezone' => 'UTC',
        'password' => Hash::make('SettingsPass1!'),
    ]);

    $this->actingAs($user)
        ->put(route('settings.profile.update'), [
            'name' => 'Name',
            'locale' => 'xx-invalid',
            'timezone' => 'UTC',
        ])
        ->assertSessionHasErrors('locale');
});

it('changes password and notifies the user', function (): void {
    $user = User::factory()->create(['password' => Hash::make('SettingsPass1!')]);

    $this->actingAs($user)
        ->post(route('settings.security.password'), [
            'current_password' => 'SettingsPass1!',
            'password' => 'SettingsPass2!',
            'password_confirmation' => 'SettingsPass2!',
        ])
        ->assertRedirect();

    expect(Hash::check('SettingsPass2!', $user->fresh()->password))->toBeTrue();
    Notification::assertSentTo($user, PasswordChangedNotification::class);
});

it('rejects wrong current password and weak passwords', function (): void {
    $user = User::factory()->create(['password' => Hash::make('SettingsPass1!')]);

    $this->actingAs($user)
        ->post(route('settings.security.password'), [
            'current_password' => 'wrong',
            'password' => 'SettingsPass2!',
            'password_confirmation' => 'SettingsPass2!',
        ])
        ->assertSessionHasErrors('current_password');

    $this->actingAs($user)
        ->post(route('settings.security.password'), [
            'current_password' => 'SettingsPass1!',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertSessionHasErrors('password');
});

it('requests and cancels staged email change', function (): void {
    $user = User::factory()->create([
        'email' => 'old@example.com',
        'password' => Hash::make('SettingsPass1!'),
    ]);

    $this->actingAs($user)
        ->post(route('settings.security.email'), ['email' => 'new@example.com'])
        ->assertRedirect();

    expect($user->fresh()->pending_email)->toBe('new@example.com');

    $this->actingAs($user)
        ->post(route('settings.security.email.cancel'))
        ->assertRedirect();

    expect($user->fresh()->pending_email)->toBeNull();
});

it('lists sessions and revokes with password confirmation', function (): void {
    $user = User::factory()->create(['password' => Hash::make('SettingsPass1!')]);

    DB::table('sessions')->insert([
        [
            'id' => 'settings-current',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'x',
            'last_activity' => time(),
        ],
        [
            'id' => 'settings-other',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.11',
            'user_agent' => 'Mozilla/5.0 Other',
            'payload' => 'y',
            'last_activity' => time() - 10,
        ],
    ]);

    $this->actingAs($user)
        ->get(route('settings.sessions'))
        ->assertOk();

    app(SessionManagementService::class)->revokeOne($user, 'settings-other', 'settings-current', true);

    expect(DB::table('sessions')->where('id', 'settings-other')->exists())->toBeFalse();
});

it('enforces critical notification preferences and unique defaults', function (): void {
    $user = User::factory()->create(['password' => Hash::make('SettingsPass1!')]);
    $service = app(NotificationPreferenceService::class);
    $service->ensureDefaults($user);

    expect(fn () => $service->updateMany($user, [[
        'category' => NotificationPreferenceCategory::Security->value,
        'channel' => NotificationPreferenceChannel::Email->value,
        'enabled' => false,
    ]]))->toThrow(ValidationException::class);

    $service->updateMany($user, [[
        'category' => NotificationPreferenceCategory::Inbox->value,
        'channel' => NotificationPreferenceChannel::Email->value,
        'enabled' => true,
    ]]);

    expect(UserNotificationPreference::query()->where('user_id', $user->id)->count())
        ->toBe(count(NotificationPreferenceCategory::cases()) * count(NotificationPreferenceChannel::cases()));

    $service->updateMarketingConsent($user, false, 'settings');
    expect($user->fresh()->identityPreference?->marketing_consent)->toBeFalse();
});

it('creates api keys once and excludes secrets from later list', function (): void {
    $user = apiKeyQuotaUser(3);
    $user->forceFill(['password' => Hash::make('SettingsPass1!')])->save();

    $result = app(SettingsApiKeyService::class)->create($user, 'Settings key', ['inboxes:read'], 'SettingsPass1!');

    expect($result->plainToken)->not->toBe('')
        ->and(collect(app(SettingsApiKeyService::class)->listForUser($user))->pluck('prefix'))
        ->toContain($result->apiKey->key_prefix);

    $listed = app(SettingsApiKeyService::class)->listForUser($user);
    expect(json_encode($listed))->not->toContain($result->plainToken);

    app(SettingsApiKeyService::class)->revoke($user, $result->apiKey, 'SettingsPass1!');
    expect($result->apiKey->fresh()->revoked_at)->not->toBeNull();
});

it('requests a privacy export foundation archive without secrets', function (): void {
    Storage::fake('local');
    config(['settings.privacy.export.disk' => 'local']);
    Queue::fake();

    $user = User::factory()->create(['password' => Hash::make('SettingsPass1!')]);
    $export = app(PrivacyPreferenceService::class)->requestExport($user, true);

    expect($export->status)->toBe(PrivacyExportStatus::Pending);
    Queue::assertPushed(ProcessPrivacyExportJob::class);

    (new ProcessPrivacyExportJob((string) $export->getKey()))->handle(app(PrivacyPreferenceService::class));

    $fresh = $export->fresh();
    expect($fresh->status)->toBe(PrivacyExportStatus::Ready);
    $payload = Storage::disk('local')->get((string) $fresh->path);
    expect($payload)->toContain('"profile"')
        ->and($payload)->not->toContain('"password"')
        ->and($payload)->not->toContain('plainToken');
});

it('closes account through settings with confirmation phrase', function (): void {
    $user = User::factory()->create(['password' => Hash::make('SettingsPass1!')]);

    $this->actingAs($user)
        ->post(route('settings.account.close'), [
            'password' => 'SettingsPass1!',
            'confirmation_phrase' => 'DELETE MY ACCOUNT',
            'reason' => 'testing',
        ])
        ->assertRedirect(route('login'));

    expect($user->fresh()->status)->toBe(UserStatus::Closed);
});

it('builds settings summary without exposing secrets', function (): void {
    $user = User::factory()->create([
        'locale' => 'en',
        'timezone' => 'UTC',
        'password' => Hash::make('SettingsPass1!'),
    ]);

    $summary = app(UserSettingsSummaryService::class)->dashboard($user, 'sess');
    expect($summary)->toHaveKeys(['profile_complete', 'email_verified', 'active_sessions', 'active_api_keys'])
        ->and(json_encode($summary))->not->toContain('password');
});

it('runs settings health command', function (): void {
    $this->artisan('settings:health --json')->assertSuccessful();
});
