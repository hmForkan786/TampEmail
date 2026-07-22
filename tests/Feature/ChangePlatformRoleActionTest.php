<?php

use App\Actions\User\ChangePlatformRoleAction;
use App\DTOs\User\ChangePlatformRoleData;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Exceptions\PlatformRoleChangeNotAllowedException;
use App\Exceptions\PlatformRoleTargetUnavailableException;
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
    config(['api.key_hash_secret' => 'platform-role-change-test-secret']);
});

/**
 * @param  list<string>|null  $permissions
 */
function roleChangeApiKey(User $user, ?array $permissions, array $overrides = []): ApiKey
{
    $credentials = app(ApiKeyTokenGenerator::class)->generate();

    return ApiKey::query()->create(array_merge([
        'user_id' => $user->id,
        'name' => 'role-change-'.uniqid(),
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => $permissions,
        'rate_limit_per_minute' => 60,
    ], $overrides));
}

function changePlatformRole(User $actor, User $target, PlatformRole $newRole): \App\DTOs\User\ChangePlatformRoleResult
{
    return app(ChangePlatformRoleAction::class)->execute(new ChangePlatformRoleData(
        actorUserId: (string) $actor->id,
        targetUserId: (string) $target->id,
        newRole: $newRole,
    ));
}

it('allows an active admin to promote a user to operator', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $user = User::factory()->create();

    $result = changePlatformRole($admin, $user, PlatformRole::Operator);

    expect($result->changed)->toBeTrue()
        ->and($result->oldRole)->toBe(PlatformRole::User)
        ->and($result->newRole)->toBe(PlatformRole::Operator)
        ->and($result->revokedKeyCount)->toBe(0)
        ->and($user->fresh()->platform_role)->toBe(PlatformRole::Operator);

    $audit = AuditLog::query()->sole();
    expect($audit->action)->toBe(ChangePlatformRoleAction::AUDIT_ACTION)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->auditable_type)->toBe(User::class)
        ->and($audit->auditable_id)->toBe($user->id)
        ->and($audit->old_values)->toBe(['platform_role' => 'user'])
        ->and($audit->new_values)->toBe(['platform_role' => 'operator'])
        ->and($audit->metadata['target_user_id'])->toBe($user->id)
        ->and($audit->metadata['revoked_key_count'])->toBe(0)
        ->and($audit->metadata['changed_at'])->toBe($result->changedAt->toIso8601String())
        ->and($audit->created_at?->timestamp)->toBe($result->changedAt->timestamp);
});

it('records demotion old and new roles with revoked key count', function (): void {
    $actor = User::factory()->platformAdmin()->create();
    $target = User::factory()->platformAdmin()->create();
    roleChangeApiKey($target, ['mail_servers:admin']);
    roleChangeApiKey($target, ['mail_servers:read']);

    $result = changePlatformRole($actor, $target, PlatformRole::Operator);

    $audit = AuditLog::query()->sole();
    expect($result->revokedKeyCount)->toBe(1)
        ->and($audit->user_id)->toBe($actor->id)
        ->and($audit->auditable_id)->toBe($target->id)
        ->and($audit->old_values)->toBe(['platform_role' => 'admin'])
        ->and($audit->new_values)->toBe(['platform_role' => 'operator'])
        ->and($audit->metadata['target_user_id'])->toBe($target->id)
        ->and($audit->metadata['revoked_key_count'])->toBe(1)
        ->and(json_encode($audit->toArray()))->not->toContain('te_live_')
        ->and(json_encode($audit->metadata))->not->toContain('key_hash');
});

it('allows an active admin to promote an operator to admin', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $operator = User::factory()->platformOperator()->create();

    $result = changePlatformRole($admin, $operator, PlatformRole::Admin);

    expect($result->changed)->toBeTrue()
        ->and($result->oldRole)->toBe(PlatformRole::Operator)
        ->and($result->newRole)->toBe(PlatformRole::Admin)
        ->and($result->revokedKeyCount)->toBe(0)
        ->and($operator->fresh()->platform_role)->toBe(PlatformRole::Admin);
});

it('revokes admin-scope keys when demoting admin to operator', function (): void {
    $actor = User::factory()->platformAdmin()->create();
    $target = User::factory()->platformAdmin()->create();
    $adminKey = roleChangeApiKey($target, ['mail_servers:admin', 'inboxes:read']);
    $readKey = roleChangeApiKey($target, ['mail_servers:read']);
    $inboxKey = roleChangeApiKey($target, ['inboxes:write']);

    $result = changePlatformRole($actor, $target, PlatformRole::Operator);

    expect($result->changed)->toBeTrue()
        ->and($result->revokedKeyCount)->toBe(1)
        ->and($adminKey->fresh()->revoked_at)->not->toBeNull()
        ->and($readKey->fresh()->revoked_at)->toBeNull()
        ->and($inboxKey->fresh()->revoked_at)->toBeNull()
        ->and($target->fresh()->platform_role)->toBe(PlatformRole::Operator);
});

it('revokes all mail-server-scope keys when demoting to user', function (): void {
    $actor = User::factory()->platformAdmin()->create();
    $operator = User::factory()->platformOperator()->create();
    $adminTarget = User::factory()->platformAdmin()->create();

    $operatorMail = roleChangeApiKey($operator, ['mail_servers:write']);
    $operatorInbox = roleChangeApiKey($operator, ['inboxes:read']);
    $adminMail = roleChangeApiKey($adminTarget, ['mail_servers:admin', 'mail_servers:read']);
    $adminInbox = roleChangeApiKey($adminTarget, ['inboxes:write']);

    $operatorResult = changePlatformRole($actor, $operator, PlatformRole::User);
    $adminResult = changePlatformRole($actor, $adminTarget, PlatformRole::User);

    expect($operatorResult->revokedKeyCount)->toBe(1)
        ->and($adminResult->revokedKeyCount)->toBe(1)
        ->and($operatorMail->fresh()->revoked_at)->not->toBeNull()
        ->and($operatorInbox->fresh()->revoked_at)->toBeNull()
        ->and($adminMail->fresh()->revoked_at)->not->toBeNull()
        ->and($adminInbox->fresh()->revoked_at)->toBeNull();
});

it('does not overwrite already-revoked key timestamps', function (): void {
    $actor = User::factory()->platformAdmin()->create();
    $target = User::factory()->platformAdmin()->create();
    $original = Carbon::parse('2026-01-01 00:00:00');
    $already = roleChangeApiKey($target, ['mail_servers:admin'], ['revoked_at' => $original]);

    $result = changePlatformRole($actor, $target, PlatformRole::Operator);

    expect($result->revokedKeyCount)->toBe(0)
        ->and($already->fresh()->revoked_at?->equalTo($original))->toBeTrue();
});

it('treats same-role requests as idempotent no-ops', function (): void {
    $actor = User::factory()->platformAdmin()->create();
    $target = User::factory()->platformOperator()->create();
    roleChangeApiKey($target, ['mail_servers:read']);

    $result = changePlatformRole($actor, $target, PlatformRole::Operator);

    expect($result->changed)->toBeFalse()
        ->and($result->revokedKeyCount)->toBe(0)
        ->and($target->fresh()->platform_role)->toBe(PlatformRole::Operator)
        ->and(ApiKey::query()->where('user_id', $target->id)->whereNull('revoked_at')->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rejects operators and ordinary users as actors', function (PlatformRole $role): void {
    $actor = User::factory()->create(['platform_role' => $role]);
    $target = User::factory()->create();

    expect(fn () => changePlatformRole($actor, $target, PlatformRole::Operator))
        ->toThrow(PlatformRoleChangeNotAllowedException::class);
    expect($target->fresh()->platform_role)->toBe(PlatformRole::User)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    PlatformRole::Operator,
    PlatformRole::User,
]);

it('rejects suspended or banned admins as actors', function (UserStatus $status): void {
    $actor = User::factory()->platformAdmin()->create(['status' => $status]);
    $target = User::factory()->create();

    expect(fn () => changePlatformRole($actor, $target, PlatformRole::Operator))
        ->toThrow(PlatformRoleChangeNotAllowedException::class);
})->with([
    UserStatus::Suspended,
    UserStatus::Banned,
]);

it('rejects self role changes', function (): void {
    $admin = User::factory()->platformAdmin()->create();

    expect(fn () => changePlatformRole($admin, $admin, PlatformRole::Operator))
        ->toThrow(PlatformRoleChangeNotAllowedException::class);
    expect($admin->fresh()->platform_role)->toBe(PlatformRole::Admin);
});

it('rejects missing or soft-deleted targets', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $deleted = User::factory()->create();
    $deleted->delete();

    expect(fn () => app(ChangePlatformRoleAction::class)->execute(new ChangePlatformRoleData(
        actorUserId: (string) $admin->id,
        targetUserId: (string) Str::uuid(),
        newRole: PlatformRole::Operator,
    )))->toThrow(PlatformRoleTargetUnavailableException::class);

    expect(fn () => changePlatformRole($admin, $deleted, PlatformRole::Operator))
        ->toThrow(PlatformRoleTargetUnavailableException::class);
});

it('rolls back role and revocation when the transaction fails', function (): void {
    $actor = User::factory()->platformAdmin()->create();
    $target = User::factory()->platformAdmin()->create();
    $key = roleChangeApiKey($target, ['mail_servers:admin']);

    $mock = Mockery::mock(ApiKeyRepositoryInterface::class);
    $mock->shouldReceive('revokeUnrevokedForUserWithAnyScope')
        ->once()
        ->andThrow(new RuntimeException('forced revoke failure'));
    app()->instance(ApiKeyRepositoryInterface::class, $mock);

    expect(fn () => changePlatformRole($actor, $target, PlatformRole::Operator))
        ->toThrow(RuntimeException::class);

    expect($target->fresh()->platform_role)->toBe(PlatformRole::Admin)
        ->and($key->fresh()->revoked_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rolls back role and key revocation when audit writing fails', function (): void {
    $actor = User::factory()->platformAdmin()->create();
    $target = User::factory()->platformAdmin()->create();
    $key = roleChangeApiKey($target, ['mail_servers:admin']);

    $writer = Mockery::mock(AuditLogWriter::class);
    $writer->shouldReceive('write')
        ->once()
        ->andThrow(new RuntimeException('forced audit failure'));
    app()->instance(AuditLogWriter::class, $writer);

    expect(fn () => changePlatformRole($actor, $target, PlatformRole::Operator))
        ->toThrow(RuntimeException::class);

    expect($target->fresh()->platform_role)->toBe(PlatformRole::Admin)
        ->and($key->fresh()->revoked_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('creates separate immutable audit records for multiple role changes', function (): void {
    $actor = User::factory()->platformAdmin()->create();
    $target = User::factory()->create();

    $firstAt = Carbon::parse('2026-07-22 12:00:00');
    Carbon::setTestNow($firstAt);
    changePlatformRole($actor, $target, PlatformRole::Operator);

    Carbon::setTestNow($firstAt->copy()->addSecond());
    changePlatformRole($actor, $target, PlatformRole::Admin);
    Carbon::setTestNow();

    $audits = AuditLog::query()->orderBy('created_at')->orderBy('id')->get();

    expect($audits)->toHaveCount(2)
        ->and($audits[0]->old_values)->toBe(['platform_role' => 'user'])
        ->and($audits[0]->new_values)->toBe(['platform_role' => 'operator'])
        ->and($audits[1]->old_values)->toBe(['platform_role' => 'operator'])
        ->and($audits[1]->new_values)->toBe(['platform_role' => 'admin'])
        ->and($audits[0]->id)->not->toBe($audits[1]->id);
});

it('does not allow mass assignment to change platform_role', function (): void {
    $user = User::factory()->create();

    $user->fill(['platform_role' => PlatformRole::Admin])->save();
    $user->update(['platform_role' => PlatformRole::Admin]);

    expect($user->fresh()->platform_role)->toBe(PlatformRole::User);
});
