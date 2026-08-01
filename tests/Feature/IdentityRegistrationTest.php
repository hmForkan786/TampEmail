<?php

declare(strict_types=1);

use App\Enums\RegistrationMode;
use App\Enums\UserStatus;
use App\Models\RegistrationInvite;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Entitlement\EntitlementService;
use App\Services\Identity\InviteService;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'identity.password.uncompromised_check' => false,
        'identity.registration.min_form_fill_ms' => 0,
        'identity.registration.honeypot_enabled' => true,
        'identity.registration.email_verification_required' => true,
    ]);
    Notification::fake();
    app(CommercialPlanFeatureSeeder::class)->run();
});

function strongPassword(): string
{
    return 'Str0ng!Passw0rd';
}

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Test User',
        'email' => 'new.user@example.test',
        'password' => strongPassword(),
        'password_confirmation' => strongPassword(),
        'terms_accepted' => '1',
        '_form_started_at' => (int) (microtime(true) * 1000) - 5000,
    ], $overrides);
}

it('blocks registration when mode is disabled', function (): void {
    config(['identity.registration.mode' => 'disabled']);

    $this->get(route('register'))->assertRedirect(route('login'));

    $this->post(route('register.store'), registrationPayload())
        ->assertRedirect(route('login'));

    expect(User::query()->where('email', 'new.user@example.test')->exists())->toBeFalse();
});

it('registers an open registration user as pending without paid subscription', function (): void {
    config(['identity.registration.mode' => 'open']);

    $this->post(route('register.store'), registrationPayload())
        ->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'new.user@example.test')->first();
    expect($user)->toBeInstanceOf(User::class)
        ->and($user->status)->toBe(UserStatus::Pending)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->platform_role->value)->toBe('user')
        ->and(Subscription::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $plan = app(EntitlementService::class)->effectivePlan($user);
    expect($plan?->slug)->toBe(EntitlementService::FREE_PLAN_SLUG);
});

it('rejects privileged field injection on registration', function (): void {
    config(['identity.registration.mode' => 'open']);

    $this->post(route('register.store'), registrationPayload([
        'status' => 'active',
        'platform_role' => 'admin',
        'is_admin' => true,
        'email_verified_at' => now()->toDateTimeString(),
        'plan_id' => 'premium',
    ]))->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'new.user@example.test')->firstOrFail();
    expect($user->status)->toBe(UserStatus::Pending)
        ->and($user->platform_role->value)->toBe('user')
        ->and($user->email_verified_at)->toBeNull();
});

it('rejects weak passwords and missing terms', function (): void {
    config(['identity.registration.mode' => 'open']);

    $this->from(route('register'))
        ->post(route('register.store'), registrationPayload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))
        ->assertSessionHasErrors('password');

    $this->from(route('register'))
        ->post(route('register.store'), registrationPayload([
            'terms_accepted' => null,
        ]))
        ->assertSessionHasErrors('terms_accepted');
});

it('silently accepts honeypot submissions without creating users', function (): void {
    config(['identity.registration.mode' => 'open']);

    $this->post(route('register.store'), registrationPayload([
        'website' => 'http://spam.test',
    ]))->assertRedirect(route('login'));

    expect(User::query()->where('email', 'new.user@example.test')->exists())->toBeFalse();
});

it('supports invite-only registration and rejects revoked invites', function (): void {
    config(['identity.registration.mode' => 'invite_only']);
    $admin = User::factory()->platformAdmin()->create();

    $created = app(InviteService::class)->create(null, 1, now()->addDay(), $admin);
    $this->post(route('register.store'), registrationPayload([
        'invite_token' => $created['plain_token'],
    ]))->assertRedirect(route('verification.notice'));

    expect(User::query()->where('email', 'new.user@example.test')->exists())->toBeTrue();

    auth()->logout();
    $this->app['session']->flush();

    $revoked = app(InviteService::class)->create(null, 1, now()->addDay(), $admin);
    app(InviteService::class)->revoke($revoked['invite'], $admin);

    $this->from(route('register'))
        ->post(route('register.store'), registrationPayload([
            'email' => 'second@example.test',
            'invite_token' => $revoked['plain_token'],
        ]))
        ->assertSessionHasErrors('invite_token');
});

it('returns a generic duplicate-email style response', function (): void {
    config(['identity.registration.mode' => 'open']);
    User::factory()->create(['email' => 'new.user@example.test']);

    auth()->logout();
    $this->app['session']->flush();

    $this->from(route('register'))
        ->post(route('register.store'), registrationPayload())
        ->assertSessionHasErrors('email');
});

it('throttles registration attempts', function (): void {
    config([
        'identity.registration.mode' => 'open',
        'identity.rate_limits.registration_per_minute' => 2,
    ]);

    for ($i = 0; $i < 2; $i++) {
        $this->post(route('register.store'), registrationPayload([
            'email' => 'same-throttle@example.test',
            'name' => "User {$i}",
        ]));
    }

    $this->post(route('register.store'), registrationPayload([
        'email' => 'same-throttle@example.test',
    ]))->assertStatus(429);
});

it('fails closed for unknown registration modes', function (): void {
    config(['identity.registration.mode' => 'totally-invalid']);
    expect(RegistrationMode::fromConfig('totally-invalid'))->toBe(RegistrationMode::Disabled);

    $this->post(route('register.store'), registrationPayload())
        ->assertRedirect(route('login'));
});
