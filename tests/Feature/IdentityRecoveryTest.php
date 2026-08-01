<?php

declare(strict_types=1);

use App\Enums\AccountRecoveryStatus;
use App\Models\AccountRecoveryRequest;
use App\Models\User;
use App\Services\Identity\AccountRecoveryService;
use App\Services\Identity\EmailChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['identity.password.uncompromised_check' => false]);
    Notification::fake();
});

it('accepts recovery submissions with a generic response', function (): void {
    User::factory()->create(['email' => 'recover@example.test']);

    $this->post(route('account.recovery.store'), [
        'claimed_email' => 'recover@example.test',
        'reason_code' => 'lost_email_access',
        'new_email' => 'new-recover@example.test',
    ])->assertSessionHas('identityStatus');

    expect(AccountRecoveryRequest::query()->count())->toBe(1);
});

it('requires admin authorization for recovery review transitions', function (): void {
    $user = User::factory()->create(['email' => 'recover2@example.test']);
    $request = app(AccountRecoveryService::class)->submit([
        'claimed_email' => 'recover2@example.test',
        'reason_code' => 'suspected_compromise',
        'new_email' => 'staged@example.test',
    ], '127.0.0.1');

    $nonAdmin = User::factory()->create();
    expect(fn () => app(AccountRecoveryService::class)->startReview($request, $nonAdmin))
        ->toThrow(RuntimeException::class);

    $admin = User::factory()->platformAdmin()->create();
    $underReview = app(AccountRecoveryService::class)->startReview($request, $admin);
    expect($underReview->status)->toBe(AccountRecoveryStatus::UnderReview);

    $approved = app(AccountRecoveryService::class)->approve($underReview, $admin);
    expect($approved->status)->toBe(AccountRecoveryStatus::Approved);

    $completed = app(AccountRecoveryService::class)->complete($approved, $admin);
    expect($completed->status)->toBe(AccountRecoveryStatus::Completed)
        ->and($user->fresh()->pending_email)->toBe('staged@example.test');

    // History is append-only.
    expect($completed->review_history)->toBeArray()
        ->and(count($completed->review_history))->toBeGreaterThan(1);
});

it('verifies staged email before replacement and preserves uniqueness', function (): void {
    $user = User::factory()->create(['email' => 'old@example.test']);
    User::factory()->create(['email' => 'taken@example.test']);
    $admin = User::factory()->platformAdmin()->create();

    expect(fn () => app(EmailChangeService::class)->stagePendingEmail($user, 'taken@example.test', $admin))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    app(EmailChangeService::class)->stagePendingEmail($user, 'brand-new@example.test', $admin);
    $user->refresh();

    $url = URL::temporarySignedRoute('account.pending-email.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1((string) $user->pending_email),
    ]);

    $this->get($url)->assertRedirect(route('login'));
    expect($user->fresh()->email)->toBe('brand-new@example.test')
        ->and($user->fresh()->pending_email)->toBeNull();
});

it('expires stale recovery requests', function (): void {
    $request = AccountRecoveryRequest::query()->create([
        'claimed_email_hash' => hash('sha256', 'x'),
        'status' => AccountRecoveryStatus::Submitted,
        'reason_code' => 'other',
        'expires_at' => now()->subHour(),
        'review_history' => [['action' => 'submitted']],
    ]);

    $count = app(AccountRecoveryService::class)->expireStale();
    expect($count)->toBe(1)
        ->and($request->fresh()->status)->toBe(AccountRecoveryStatus::Cancelled);
});
