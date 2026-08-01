<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\Identity\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'identity.password.uncompromised_check' => false,
        'identity.password_reset.revoke_sessions' => true,
        'session.driver' => 'database',
    ]);
    Notification::fake();
});

it('returns a generic response for known and unknown emails', function (): void {
    User::factory()->create(['email' => 'known@example.test']);

    $this->post(route('password.email'), ['email' => 'known@example.test'])
        ->assertSessionHas('identityStatus');

    $this->post(route('password.email'), ['email' => 'unknown@example.test'])
        ->assertSessionHas('identityStatus');
});

it('resets password with a valid token and rotates remember token', function (): void {
    $user = User::factory()->create([
        'email' => 'reset@example.test',
        'password' => Hash::make('OldPassw0rd!'),
        'remember_token' => 'old-remember',
    ]);

    $token = Password::broker()->createToken($user);

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => 'reset@example.test',
        'password' => 'NewPassw0rd!99',
        'password_confirmation' => 'NewPassw0rd!99',
    ])->assertRedirect(route('login'));

    $fresh = $user->fresh();
    expect(Hash::check('NewPassw0rd!99', $fresh->password))->toBeTrue()
        ->and($fresh->remember_token)->not->toBe('old-remember')
        ->and($fresh->status)->toBe(UserStatus::Active);
});

it('does not reactivate suspended users on password reset', function (): void {
    $user = User::factory()->suspended()->create([
        'email' => 'suspended-reset@example.test',
        'password' => Hash::make('OldPassw0rd!'),
    ]);

    // Broker may still create a token; completion must fail closed for blocked accounts.
    $this->post(route('password.email'), ['email' => 'suspended-reset@example.test']);
    Notification::assertNothingSent();

    $token = Password::broker()->createToken($user);
    $this->from(route('password.reset', ['token' => $token]))
        ->post(route('password.store'), [
            'token' => $token,
            'email' => 'suspended-reset@example.test',
            'password' => 'NewPassw0rd!99',
            'password_confirmation' => 'NewPassw0rd!99',
        ])
        ->assertSessionHasErrors();

    expect($user->fresh()->status)->toBe(UserStatus::Suspended);
});

it('rejects reused and weak reset tokens/passwords', function (): void {
    $user = User::factory()->create(['email' => 'reuse@example.test']);
    $token = Password::broker()->createToken($user);

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => 'reuse@example.test',
        'password' => 'NewPassw0rd!99',
        'password_confirmation' => 'NewPassw0rd!99',
    ])->assertRedirect(route('login'));

    $this->from(route('password.reset', ['token' => $token]))
        ->post(route('password.store'), [
            'token' => $token,
            'email' => 'reuse@example.test',
            'password' => 'NewPassw0rd!99',
            'password_confirmation' => 'NewPassw0rd!99',
        ])
        ->assertSessionHasErrors();

    $token2 = Password::broker()->createToken($user);
    $this->from(route('password.reset', ['token' => $token2]))
        ->post(route('password.store'), [
            'token' => $token2,
            'email' => 'reuse@example.test',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])
        ->assertSessionHasErrors('password');
});

it('sends reset notification for eligible accounts', function (): void {
    $user = User::factory()->create(['email' => 'notify-reset@example.test']);

    $this->post(route('password.email'), ['email' => 'notify-reset@example.test']);

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});
