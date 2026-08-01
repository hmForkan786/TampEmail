<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Identity\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['identity.password.uncompromised_check' => false]);
    Notification::fake();
});

it('verifies a signed link and transitions pending to active', function (): void {
    $user = User::factory()->pending()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($url)->assertRedirect();

    $user->refresh();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->status)->toBe(UserStatus::Active);
});

it('rejects expired and tampered verification links', function (): void {
    $user = User::factory()->pending()->unverified()->create();

    $expired = URL::temporarySignedRoute('verification.verify', now()->subMinutes(5), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);
    $this->actingAs($user)->get($expired)->assertStatus(403);

    $tampered = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
        'id' => $user->id,
        'hash' => sha1('other@example.test'),
    ]);
    $this->actingAs($user)->get($tampered)->assertRedirect(route('login'));
});

it('is idempotent for already verified users', function (): void {
    $user = User::factory()->create();
    expect(app(EmailVerificationService::class)->markVerified($user))->toBeTrue();
});

it('does not activate suspended users via verification', function (): void {
    $user = User::factory()->suspended()->unverified()->create();

    expect(fn () => app(EmailVerificationService::class)->markVerified($user))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect($user->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($user->fresh()->email_verified_at)->toBeNull();
});

it('blocks verified product access until email is verified', function (): void {
    $user = User::factory()->pending()->unverified()->create();

    $this->actingAs($user)
        ->get(route('outbound-messages.index'))
        ->assertRedirect(route('verification.notice'));
});

it('rate limits verification resend', function (): void {
    config(['identity.rate_limits.verification_resend_per_minute' => 1]);
    $user = User::factory()->pending()->unverified()->create();

    $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
    $this->actingAs($user)->post(route('verification.send'))->assertStatus(429);
});
