<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Actions\ApiKey\UpdateApiKeyAction;
use App\DTOs\ApiKey\CreateApiKeyData;
use App\DTOs\ApiKey\UpdateApiKeyData;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiKeyScopeNotAllowedException;
use App\Exceptions\InvalidApiKeyScopeException;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'scope-authz-test-secret']);
});

function scopeAuthzUser(PlatformRole $role = PlatformRole::User, UserStatus $status = UserStatus::Active): User
{
    return User::factory()->create([
        'platform_role' => $role,
        'status' => $status,
    ]);
}

function issueFor(User $user, ?array $permissions): ApiKey
{
    return app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'scope-authz',
        permissions: $permissions,
        user: $user,
    )->apiKey;
}

function createDataFor(User $user, ?array $permissions): CreateApiKeyData
{
    $credentials = app(ApiKeyTokenGenerator::class)->generate();

    return new CreateApiKeyData(
        userId: $user->id,
        name: 'scope-authz-execute',
        keyPrefix: $credentials['key_prefix'],
        keyHash: $credentials['key_hash'],
        permissions: $permissions,
        rateLimitPerMinute: 60,
        expiresAt: null,
        revokedAt: null,
        metadata: null,
    );
}

function updatePermissionsOnly(?array $permissions): UpdateApiKeyData
{
    return new UpdateApiKeyData(
        userId: null,
        name: null,
        keyPrefix: null,
        keyHash: null,
        permissions: $permissions,
        rateLimitPerMinute: null,
        expiresAt: null,
        revokedAt: null,
        metadata: null,
    );
}

it('allows an ordinary user to issue inbox scopes', function (): void {
    $user = scopeAuthzUser();
    $key = issueFor($user, ['inboxes:write', 'inboxes:read', 'inboxes:read']);

    expect($key->permissions)->toBe(['inboxes:read', 'inboxes:write']);
});

it('rejects ordinary users issuing mail server scopes', function (string $scope): void {
    $user = scopeAuthzUser();

    expect(fn () => issueFor($user, [$scope]))
        ->toThrow(ApiKeyScopeNotAllowedException::class);

    expect(ApiKey::query()->count())->toBe(0);
})->with([
    'mail_servers:read',
    'mail_servers:write',
    'mail_servers:admin',
]);

it('allows operators to issue mail server read and write but not admin', function (): void {
    $operator = scopeAuthzUser(PlatformRole::Operator);

    expect(issueFor($operator, ['mail_servers:write', 'mail_servers:read'])->permissions)
        ->toBe(['mail_servers:read', 'mail_servers:write']);

    expect(fn () => issueFor($operator, ['mail_servers:admin']))
        ->toThrow(ApiKeyScopeNotAllowedException::class);
});

it('allows admins to issue every canonical scope', function (): void {
    $admin = scopeAuthzUser(PlatformRole::Admin);

    $key = issueFor($admin, [
        'inboxes:write',
        'mail_servers:admin',
        'mail_servers:read',
        'inboxes:read',
        'mail_servers:write',
    ]);

    expect($key->permissions)->toBe([
        'mail_servers:read',
        'mail_servers:write',
        'mail_servers:admin',
        'inboxes:read',
        'inboxes:write',
    ]);
});

it('rejects unknown blank and non-string scopes without persisting', function (): void {
    $user = scopeAuthzUser();

    expect(fn () => issueFor($user, ['not:a_scope']))->toThrow(InvalidApiKeyScopeException::class);
    expect(fn () => issueFor($user, ['']))->toThrow(InvalidApiKeyScopeException::class);
    expect(fn () => issueFor($user, [123]))->toThrow(InvalidApiKeyScopeException::class);
    expect(ApiKey::query()->count())->toBe(0);
});

it('accepts null and empty permissions as no scopes', function (): void {
    $user = scopeAuthzUser();

    expect(issueFor($user, null)->permissions)->toBeNull();
    expect(issueFor($user, [])->permissions)->toBe([]);
});

it('enforces the same policy on execute and the service layer', function (): void {
    $user = scopeAuthzUser();
    $operator = scopeAuthzUser(PlatformRole::Operator);

    expect(fn () => app(CreateApiKeyAction::class)->execute(
        createDataFor($user, ['mail_servers:read']),
        $user,
    ))->toThrow(ApiKeyScopeNotAllowedException::class);

    $viaService = app(ApiKeyService::class)->issue(
        userId: $operator->id,
        name: 'service-issue',
        permissions: ['mail_servers:read'],
        user: $operator,
    )->apiKey;

    expect($viaService->permissions)->toBe(['mail_servers:read']);
});

it('rejects unauthorized permission escalation on update without partial writes', function (): void {
    $user = scopeAuthzUser();
    $key = issueFor($user, ['inboxes:read']);
    $original = $key->permissions;

    expect(fn () => app(UpdateApiKeyAction::class)->execute(
        $key,
        updatePermissionsOnly(['mail_servers:read']),
    ))->toThrow(ApiKeyScopeNotAllowedException::class);

    expect($key->fresh()->permissions)->toBe($original)
        ->and($key->fresh()->name)->toBe('scope-authz');
});

it('clears permissions with an explicit empty array and leaves missing permissions unchanged', function (): void {
    $user = scopeAuthzUser();
    $key = issueFor($user, ['inboxes:read', 'inboxes:write']);

    $cleared = app(UpdateApiKeyAction::class)->execute($key, updatePermissionsOnly([]));
    expect($cleared->permissions)->toBe([]);

    $renamed = app(UpdateApiKeyAction::class)->execute(
        $cleared,
        new UpdateApiKeyData(
            userId: null,
            name: 'renamed-key',
            keyPrefix: null,
            keyHash: null,
            permissions: null,
            rateLimitPerMinute: null,
            expiresAt: null,
            revokedAt: null,
            metadata: null,
        ),
    );

    expect($renamed->name)->toBe('renamed-key')
        ->and($renamed->permissions)->toBe([]);
});

it('rejects inactive owners for inbox scopes', function (): void {
    $user = scopeAuthzUser(PlatformRole::User, UserStatus::Suspended);

    expect(fn () => issueFor($user, ['inboxes:read']))
        ->toThrow(ApiKeyScopeNotAllowedException::class);
});
