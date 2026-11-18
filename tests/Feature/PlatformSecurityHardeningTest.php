<?php

declare(strict_types=1);

use App\Actions\User\ChangeUserStatusAction;
use App\DTOs\MailServer\MailServerFiltersData;
use App\DTOs\User\ChangeUserStatusData;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

it('throttles login attempts by email and ip', function (): void {
    config(['abuse.rate_limits.login_per_minute' => 3]);
    RateLimiter::clear('login');

    $user = User::factory()->create([
        'email' => 'login-throttle@example.test',
        'password' => Hash::make('correct-password'),
    ]);

    for ($i = 0; $i < 3; $i++) {
        $this->from(route('login'))
            ->post(route('login.store'), [
                '_token' => csrf_token(),
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    $this->from(route('login'))
        ->post(route('login.store'), [
            '_token' => csrf_token(),
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
        ->assertStatus(429);
});

it('logs out inactive users on subsequent authenticated web requests', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('page-test-password'),
        'status' => UserStatus::Active,
    ]);

    $this->actingAs($user)
        ->get(route('outbound-messages.index'))
        ->assertOk();

    $user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->actingAs($user)
        ->get(route('outbound-messages.index'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('clears remember tokens when an admin suspends a user', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create([
        'remember_token' => 'remember-me-token-value',
        'status' => UserStatus::Active,
    ]);

    app(ChangeUserStatusAction::class)->execute(new ChangeUserStatusData(
        actorUserId: (string) $admin->id,
        targetUserId: (string) $user->id,
        newStatus: UserStatus::Suspended,
    ));

    expect($user->fresh()->remember_token)->toBeNull()
        ->and($user->fresh()->status)->toBe(UserStatus::Suspended);
});

it('allowlists mail server sort columns and directions', function (): void {
    $safe = MailServerFiltersData::fromArray([
        'sort_by' => 'hostname',
        'sort_direction' => 'asc',
    ]);
    expect($safe->sortBy)->toBe('hostname')
        ->and($safe->sortDirection)->toBe('asc');

    $unsafe = MailServerFiltersData::fromArray([
        'sort_by' => 'id; drop table users--',
        'sort_direction' => 'sideways',
    ]);
    expect($unsafe->sortBy)->toBe('created_at')
        ->and($unsafe->sortDirection)->toBe('desc');
});

it('rejects login for suspended users without creating a session', function (): void {
    $user = User::factory()->create([
        'email' => 'suspended-login@example.test',
        'password' => Hash::make('correct-password'),
        'status' => UserStatus::Suspended,
    ]);

    $this->from(route('login'))
        ->post(route('login.store'), [
            '_token' => csrf_token(),
            'email' => $user->email,
            'password' => 'correct-password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
