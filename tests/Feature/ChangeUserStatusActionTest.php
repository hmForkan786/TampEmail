<?php

use App\Actions\User\ChangeUserStatusAction;
use App\DTOs\User\ChangeUserStatusData;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Exceptions\UserStatusChangeNotAllowedException;
use App\Exceptions\UserStatusTargetUnavailableException;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'user-status-change-test-secret']);
});

/**
 * @param  list<string>|null  $permissions
 */
function statusChangeApiKey(User $user, ?array $permissions = ['inboxes:read'], array $overrides = []): ApiKey
{
    $credentials = app(ApiKeyTokenGenerator::class)->generate();

    return ApiKey::query()->create(array_merge([
        'user_id' => $user->id,
        'name' => 'status-change-'.uniqid(),
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => $permissions,
        'rate_limit_per_minute' => 60,
    ], $overrides));
}

function changeUserStatus(User $actor, User $target, UserStatus $newStatus): \App\DTOs\User\ChangeUserStatusResult
{
    return app(ChangeUserStatusAction::class)->execute(new ChangeUserStatusData(
        actorUserId: (string) $actor->id,
        targetUserId: (string) $target->id,
        newStatus: $newStatus,
    ));
}

it('allows an active admin to suspend an active user', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create();
    statusChangeApiKey($user);
    statusChangeApiKey($user, ['mail_servers:read']);

    $result = changeUserStatus($admin, $user, UserStatus::Suspended);

    expect($result->changed)->toBeTrue()
        ->and($result->oldStatus)->toBe(UserStatus::Active)
        ->and($result->newStatus)->toBe(UserStatus::Suspended)
        ->and($result->revokedKeyCount)->toBe(2)
        ->and($user->fresh()->status)->toBe(UserStatus::Suspended);

    $audit = AuditLog::query()->sole();
    expect($audit->action)->toBe(ChangeUserStatusAction::AUDIT_ACTION)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->auditable_type)->toBe(User::class)
        ->and($audit->auditable_id)->toBe($user->id)
        ->and($audit->old_values)->toBe(['status' => 'active'])
        ->and($audit->new_values)->toBe(['status' => 'suspended'])
        ->and($audit->metadata['target_user_id'])->toBe($user->id)
        ->and($audit->metadata['revoked_key_count'])->toBe(2)
        ->and($audit->metadata['changed_at'])->toBe($result->changedAt->toIso8601String())
        ->and($audit->created_at?->timestamp)->toBe($result->changedAt->timestamp)
        ->and(json_encode($audit->toArray()))->not->toContain('te_live_')
        ->and(json_encode($audit->metadata))->not->toContain('key_hash');
});

it('revokes all non-revoked keys when banning an active user', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create();
    $active = statusChangeApiKey($user);
    $alreadyRevokedAt = Carbon::parse('2026-01-01 00:00:00');
    $revoked = statusChangeApiKey($user, ['inboxes:write'], ['revoked_at' => $alreadyRevokedAt]);

    $result = changeUserStatus($admin, $user, UserStatus::Banned);

    expect($result->revokedKeyCount)->toBe(1)
        ->and($user->fresh()->status)->toBe(UserStatus::Banned)
        ->and($active->fresh()->revoked_at)->not->toBeNull()
        ->and($revoked->fresh()->revoked_at?->equalTo($alreadyRevokedAt))->toBeTrue();

    $audit = AuditLog::query()->sole();
    expect($audit->old_values)->toBe(['status' => 'active'])
        ->and($audit->new_values)->toBe(['status' => 'banned'])
        ->and($audit->metadata['revoked_key_count'])->toBe(1);
});

it('revokes all non-revoked keys when moving an active user to pending', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create();
    statusChangeApiKey($user);
    statusChangeApiKey($user);

    $result = changeUserStatus($admin, $user, UserStatus::Pending);

    expect($result->changed)->toBeTrue()
        ->and($result->revokedKeyCount)->toBe(2)
        ->and($user->fresh()->status)->toBe(UserStatus::Pending)
        ->and(ApiKey::query()->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('does not restore revoked keys when reactivating a user', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->suspended()->create();
    $revokedAt = Carbon::parse('2026-03-15 12:00:00');
    $key = statusChangeApiKey($user, ['inboxes:read'], ['revoked_at' => $revokedAt]);

    $result = changeUserStatus($admin, $user, UserStatus::Active);

    expect($result->changed)->toBeTrue()
        ->and($result->revokedKeyCount)->toBe(0)
        ->and($user->fresh()->status)->toBe(UserStatus::Active)
        ->and($key->fresh()->revoked_at?->equalTo($revokedAt))->toBeTrue();

    $audit = AuditLog::query()->sole();
    expect($audit->old_values)->toBe(['status' => 'suspended'])
        ->and($audit->new_values)->toBe(['status' => 'active'])
        ->and($audit->metadata['revoked_key_count'])->toBe(0);
});

it('treats same-status requests as idempotent no-ops', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create();
    $key = statusChangeApiKey($user);

    $result = changeUserStatus($admin, $user, UserStatus::Active);

    expect($result->changed)->toBeFalse()
        ->and($result->revokedKeyCount)->toBe(0)
        ->and($key->fresh()->revoked_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rejects operators and ordinary users as actors', function (PlatformRole $role): void {
    $actor = User::factory()->create(['platform_role' => $role]);
    $target = User::factory()->create();

    expect(fn () => changeUserStatus($actor, $target, UserStatus::Suspended))
        ->toThrow(UserStatusChangeNotAllowedException::class);

    expect($target->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    PlatformRole::Operator,
    PlatformRole::User,
]);

it('rejects suspended or banned admins as actors', function (UserStatus $status): void {
    $actor = User::factory()->platformAdmin()->create(['status' => $status]);
    $target = User::factory()->create();

    expect(fn () => changeUserStatus($actor, $target, UserStatus::Suspended))
        ->toThrow(UserStatusChangeNotAllowedException::class);

    expect($target->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    UserStatus::Suspended,
    UserStatus::Banned,
]);

it('rejects self status changes', function (): void {
    $admin = User::factory()->platformAdmin()->create();

    expect(fn () => changeUserStatus($admin, $admin, UserStatus::Suspended))
        ->toThrow(UserStatusChangeNotAllowedException::class);

    expect($admin->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rejects missing or soft-deleted targets', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $deleted = User::factory()->create();
    $deleted->delete();

    expect(fn () => app(ChangeUserStatusAction::class)->execute(new ChangeUserStatusData(
        actorUserId: (string) $admin->id,
        targetUserId: (string) Str::uuid(),
        newStatus: UserStatus::Suspended,
    )))->toThrow(UserStatusTargetUnavailableException::class);

    expect(fn () => changeUserStatus($admin, $deleted, UserStatus::Suspended))
        ->toThrow(UserStatusTargetUnavailableException::class);

    expect(AuditLog::query()->count())->toBe(0);
});

it('does not overwrite already-revoked key timestamps', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create();
    $prior = Carbon::parse('2026-01-01 00:00:00');
    $revoked = statusChangeApiKey($user, ['inboxes:read'], ['revoked_at' => $prior]);
    $active = statusChangeApiKey($user);

    changeUserStatus($admin, $user, UserStatus::Suspended);

    expect($revoked->fresh()->revoked_at?->equalTo($prior))->toBeTrue()
        ->and($active->fresh()->revoked_at)->not->toBeNull();
});

it('rolls back status and key revocation when audit writing fails', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create();
    $key = statusChangeApiKey($user);

    $writer = Mockery::mock(AuditLogWriter::class);
    $writer->shouldReceive('write')
        ->once()
        ->andThrow(new RuntimeException('forced audit failure'));
    app()->instance(AuditLogWriter::class, $writer);

    expect(fn () => changeUserStatus($admin, $user, UserStatus::Banned))
        ->toThrow(RuntimeException::class);

    expect($user->fresh()->status)->toBe(UserStatus::Active)
        ->and($key->fresh()->revoked_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rolls back status when bulk revocation fails', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create();

    $mock = Mockery::mock(ApiKeyRepositoryInterface::class);
    $mock->shouldReceive('revokeAllUnrevokedForUser')
        ->once()
        ->andThrow(new RuntimeException('forced revoke failure'));
    app()->instance(ApiKeyRepositoryInterface::class, $mock);

    expect(fn () => changeUserStatus($admin, $user, UserStatus::Suspended))
        ->toThrow(RuntimeException::class);

    expect($user->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('does not allow mass assignment to change status', function (): void {
    $user = User::factory()->create();

    $user->fill(['status' => UserStatus::Banned])->save();
    $user->update(['status' => UserStatus::Banned]);

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

it('revokes keys when transitioning between non-active statuses', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->pending()->create();
    $key = statusChangeApiKey($user);

    $result = changeUserStatus($admin, $user, UserStatus::Banned);

    expect($result->changed)->toBeTrue()
        ->and($result->revokedKeyCount)->toBe(1)
        ->and($key->fresh()->revoked_at)->not->toBeNull()
        ->and($user->fresh()->status)->toBe(UserStatus::Banned);
});
