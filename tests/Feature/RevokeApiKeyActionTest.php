<?php

use App\Actions\ApiKey\RevokeApiKeyAction;
use App\DTOs\ApiKey\RevokeApiKeyData;
use App\Enums\ApiKeyScope;
use App\Enums\UserStatus;
use App\Exceptions\ApiKeyRevocationNotAllowedException;
use App\Exceptions\ApiKeyRevocationTargetUnavailableException;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'revoke-api-key-action-test-secret']);
});

/**
 * @param  list<string>|null  $permissions
 * @return array{api_key: ApiKey, plain_token: string, key_hash: string}
 */
function createApiKeyForRevokeActionTest(User $owner, ?array $permissions = null, array $overrides = []): array
{
    $credentials = app(ApiKeyTokenGenerator::class)->generate();

    $apiKey = ApiKey::query()->create(array_merge([
        'user_id' => $owner->id,
        'name' => 'revoke-action-'.uniqid(),
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => $permissions ?? [ApiKeyScope::InboxesRead->value],
        'rate_limit_per_minute' => 60,
    ], $overrides));

    return [
        'api_key' => $apiKey->fresh(),
        'plain_token' => $credentials['plain_token'],
        'key_hash' => $credentials['key_hash'],
    ];
}

function revokeApiKey(User $actor, ApiKey $apiKey, string $source = 'filament'): \App\DTOs\ApiKey\RevokeApiKeyResult
{
    return app(RevokeApiKeyAction::class)->execute(new RevokeApiKeyData(
        actorUserId: (string) $actor->getKey(),
        apiKeyId: (string) $apiKey->getKey(),
        source: $source,
    ));
}

it('allows an active admin to revoke an active api key and writes an audit log', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-22 16:00:00'));

    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();
    $created = createApiKeyForRevokeActionTest($owner, [
        ApiKeyScope::InboxesRead->value,
        ApiKeyScope::InboxesWrite->value,
    ]);
    $apiKey = $created['api_key'];
    $permissionsBefore = $apiKey->permissions;
    $prefixBefore = $apiKey->key_prefix;

    $result = revokeApiKey($admin, $apiKey);

    expect($result->changed)->toBeTrue()
        ->and($result->revokedAt->equalTo(Carbon::parse('2026-07-22 16:00:00')))->toBeTrue()
        ->and($apiKey->fresh()->revoked_at?->equalTo(Carbon::parse('2026-07-22 16:00:00')))->toBeTrue()
        ->and($apiKey->fresh()->permissions)->toBe($permissionsBefore)
        ->and($apiKey->fresh()->key_hash)->toBe($created['key_hash'])
        ->and($apiKey->fresh()->key_prefix)->toBe($prefixBefore);

    $audit = AuditLog::query()->sole();
    expect($audit->action)->toBe(RevokeApiKeyAction::AUDIT_ACTION)
        ->and($audit->user_id)->toBe((string) $admin->getKey())
        ->and($audit->auditable_type)->toBe(ApiKey::class)
        ->and($audit->auditable_id)->toBe((string) $apiKey->getKey())
        ->and($audit->old_values)->toBe(['revoked_at' => null])
        ->and($audit->new_values)->toBe(['revoked_at' => Carbon::parse('2026-07-22 16:00:00')->toIso8601String()])
        ->and($audit->metadata)->toBe([
            'target_api_key_id' => (string) $apiKey->getKey(),
            'owner_user_id' => (string) $owner->getKey(),
            'source' => 'filament',
        ])
        ->and(json_encode($audit->toArray()))->not->toContain($created['plain_token'])
        ->and(json_encode($audit->toArray()))->not->toContain($created['key_hash'])
        ->and(json_encode($audit->metadata))->not->toContain('key_hash')
        ->and(json_encode($audit->metadata))->not->toContain('te_live_');

    Carbon::setTestNow();
});

it('revokes an expired non-revoked api key', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-22 16:00:00'));

    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();
    $apiKey = createApiKeyForRevokeActionTest($owner, [ApiKeyScope::InboxesRead->value], [
        'expires_at' => now()->subHour(),
    ])['api_key'];

    $result = revokeApiKey($admin, $apiKey);

    expect($result->changed)->toBeTrue()
        ->and($apiKey->fresh()->revoked_at?->equalTo(Carbon::parse('2026-07-22 16:00:00')))->toBeTrue()
        ->and(AuditLog::query()->where('action', RevokeApiKeyAction::AUDIT_ACTION)->count())->toBe(1);

    Carbon::setTestNow();
});

it('is an idempotent no-op for already revoked keys without writing audit', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();
    $prior = Carbon::parse('2026-01-01 00:00:00');
    $apiKey = createApiKeyForRevokeActionTest($owner, [ApiKeyScope::InboxesRead->value], [
        'revoked_at' => $prior,
    ])['api_key'];

    $result = revokeApiKey($admin, $apiKey);

    expect($result->changed)->toBeFalse()
        ->and($result->previousRevokedAt?->equalTo($prior))->toBeTrue()
        ->and($apiKey->fresh()->revoked_at?->equalTo($prior))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('denies operators ordinary users and inactive admins on direct invocation', function (string $actorType): void {
    $actor = match ($actorType) {
        'operator' => User::factory()->platformOperator()->create(),
        'ordinary user' => User::factory()->create(),
        'suspended admin' => User::factory()->platformAdmin()->create(['status' => UserStatus::Suspended]),
        'banned admin' => User::factory()->platformAdmin()->create(['status' => UserStatus::Banned]),
        default => throw new \RuntimeException("Unknown actor type [{$actorType}]."),
    };
    $owner = User::factory()->create();
    $apiKey = createApiKeyForRevokeActionTest($owner)['api_key'];

    expect(fn () => revokeApiKey($actor, $apiKey))
        ->toThrow(ApiKeyRevocationNotAllowedException::class);

    expect($apiKey->fresh()->revoked_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'operator',
    'ordinary user',
    'suspended admin',
    'banned admin',
]);

it('denies a missing actor and missing api key', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();
    $apiKey = createApiKeyForRevokeActionTest($owner)['api_key'];

    expect(fn () => app(RevokeApiKeyAction::class)->execute(new RevokeApiKeyData(
        actorUserId: (string) Str::uuid(),
        apiKeyId: (string) $apiKey->getKey(),
    )))->toThrow(ApiKeyRevocationNotAllowedException::class);

    expect(fn () => app(RevokeApiKeyAction::class)->execute(new RevokeApiKeyData(
        actorUserId: (string) $admin->getKey(),
        apiKeyId: (string) Str::uuid(),
    )))->toThrow(ApiKeyRevocationTargetUnavailableException::class);

    expect($apiKey->fresh()->revoked_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rolls back revocation when audit writing fails', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $owner = User::factory()->create();
    $created = createApiKeyForRevokeActionTest($owner);
    $apiKey = $created['api_key'];

    $writer = Mockery::mock(AuditLogWriter::class);
    $writer->shouldReceive('write')
        ->once()
        ->andThrow(new RuntimeException('forced audit failure'));
    app()->instance(AuditLogWriter::class, $writer);

    expect(fn () => revokeApiKey($admin, $apiKey))
        ->toThrow(RuntimeException::class);

    expect($apiKey->fresh()->revoked_at)->toBeNull()
        ->and($apiKey->fresh()->permissions)->toBe([ApiKeyScope::InboxesRead->value])
        ->and($apiKey->fresh()->key_hash)->toBe($created['key_hash'])
        ->and(AuditLog::query()->count())->toBe(0);
});
