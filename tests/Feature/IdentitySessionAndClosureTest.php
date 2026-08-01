<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Identity\AccountClosureService;
use App\Services\Identity\SessionManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['session.driver' => 'database']);
    Notification::fake();
});

it('lists and revokes owner sessions with password confirmation', function (): void {
    $user = User::factory()->create(['password' => Hash::make('SessionPass1!')]);
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        [
            'id' => 'sess-current',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'x',
            'last_activity' => time(),
        ],
        [
            'id' => 'sess-other',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.11',
            'user_agent' => 'Mozilla/5.0 Other',
            'payload' => 'y',
            'last_activity' => time() - 10,
        ],
        [
            'id' => 'sess-foreign',
            'user_id' => $other->id,
            'ip_address' => '203.0.113.12',
            'user_agent' => 'Mozilla/5.0 Foreign',
            'payload' => 'z',
            'last_activity' => time(),
        ],
    ]);

    $this->actingAs($user)
        ->get(route('account.sessions'))
        ->assertOk();

    $service = app(SessionManagementService::class);
    $listed = $service->listForUser($user, 'sess-current');
    expect(count($listed))->toBeGreaterThanOrEqual(2);

    $service->revokeOne($user, 'sess-other', 'sess-current', true);
    expect(DB::table('sessions')->where('id', 'sess-other')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'sess-foreign')->exists())->toBeTrue();

    $service->revokeOthers($user, 'sess-current', true);
    expect(DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', 'sess-current')->count())->toBe(0);
});

it('closes an account and blocks future login', function (): void {
    $user = User::factory()->create(['password' => Hash::make('ClosePass1!')]);

    app(AccountClosureService::class)->requestClosure($user, true);

    $fresh = $user->fresh();
    expect($fresh->status)->toBe(UserStatus::Closed)
        ->and($fresh->closed_at)->not->toBeNull();

    $this->from(route('login'))
        ->post(route('login.store'), [
            'email' => $fresh->email,
            'password' => 'ClosePass1!',
        ])
        ->assertSessionHasErrors('email');
});
