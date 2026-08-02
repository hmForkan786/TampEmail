<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
});

it('logs in active verified users and regenerates the session', function (): void {
    $user = User::factory()->create([
        'email' => 'active@example.test',
        'password' => Hash::make('correct-password'),
    ]);

    $this->post(route('login.store'), [
        'email' => 'active@example.test',
        'password' => 'correct-password',
    ])->assertRedirect(route('mailbox.index'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('allows pending users to login but redirects to verification notice', function (): void {
    $user = User::factory()->pending()->unverified()->create([
        'email' => 'pending@example.test',
        'password' => Hash::make('correct-password'),
    ]);

    $this->post(route('login.store'), [
        'email' => 'pending@example.test',
        'password' => 'correct-password',
    ])->assertRedirect(route('verification.notice'));

    $this->assertAuthenticatedAs($user);
});

it('rejects suspended banned and closed users with a generic message', function (UserStatus $status): void {
    User::factory()->create([
        'email' => 'blocked@example.test',
        'password' => Hash::make('correct-password'),
        'status' => $status,
    ]);

    $this->from(route('login'))
        ->post(route('login.store'), [
            'email' => 'blocked@example.test',
            'password' => 'correct-password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
})->with([
    UserStatus::Suspended,
    UserStatus::Banned,
    UserStatus::Closed,
]);

it('uses a generic invalid credential response', function (): void {
    $this->from(route('login'))
        ->post(route('login.store'), [
            'email' => 'missing@example.test',
            'password' => 'wrong',
        ])
        ->assertSessionHasErrors('email');
});
