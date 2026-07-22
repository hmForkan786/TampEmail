<?php

use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequireApiKeyScope;
use App\Models\ApiKey;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\ApiKey\ApiKeyResolver;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use App\Services\ApiKey\ApiKeyTokenHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'middleware-test-secret']);
});

function apiKeyMiddlewareRequest(?string $authorization): Request
{
    $request = Request::create('/api/v1/test');

    if ($authorization !== null) {
        $request->headers->set('Authorization', $authorization);
    }

    return $request;
}

/**
 * @param  list<string>|null  $permissions
 * @return array{0: User, 1: ApiKey, 2: string}
 */
function installScopedApiKey(
    PlatformRole $role,
    ?array $permissions,
    string $requiredScope,
    UserStatus $status = UserStatus::Active,
): array {
    $user = User::factory()->create([
        'platform_role' => $role,
        'status' => $status,
    ]);

    $hasher = new ApiKeyTokenHasher;
    $credentials = (new ApiKeyTokenGenerator($hasher))->generate();

    $apiKey = new ApiKey([
        'user_id' => $user->id,
        'name' => 'middleware-key',
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => $permissions,
        'rate_limit_per_minute' => 60,
    ]);
    $apiKey->setRelation('user', $user);

    $repository = Mockery::mock(ApiKeyRepositoryInterface::class);
    $repository->allows('findActiveByPrefixAndHash')->andReturn($apiKey);
    app()->instance(ApiKeyResolver::class, new ApiKeyResolver($repository, $hasher));

    return [$user, $apiKey, $credentials['plain_token'], $requiredScope];
}

function runScopedPipeline(string $token, string $requiredScope): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
{
    return app(Pipeline::class)
        ->send(apiKeyMiddlewareRequest('Bearer '.$token))
        ->through([
            AuthenticateApiKey::class,
            RequireApiKeyScope::class.':'.$requiredScope,
        ])
        ->then(fn (Request $request) => response()->json([
            'ok' => true,
            'has_context' => $request->attributes->get('apiKeyContext') !== null,
            'owner_id' => $request->attributes->get('apiKeyOwner')?->id,
        ]));
}

it('rejects missing and malformed bearer headers', function (): void {
    foreach ([null, 'Basic abc', 'Bearer'] as $authorization) {
        $response = app(Pipeline::class)->send(apiKeyMiddlewareRequest($authorization))
            ->through([AuthenticateApiKey::class])
            ->then(fn () => response()->json(['ok' => true]));

        expect($response->getStatusCode())->toBe(401)
            ->and($response->getData(true)['error']['code'] ?? null)->toBe('unauthenticated');
    }
});

it('allows an active user inbox scope key', function (): void {
    [, , $token] = installScopedApiKey(PlatformRole::User, ['inboxes:read'], 'inboxes:read');

    $response = runScopedPipeline($token, 'inboxes:read');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['has_context'])->toBeTrue();
});

it('denies a user key that stores legacy mail server scopes', function (): void {
    [, , $token] = installScopedApiKey(
        PlatformRole::User,
        ['inboxes:read', 'mail_servers:read'],
        'inboxes:read',
    );

    $response = runScopedPipeline($token, 'inboxes:read');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['error']['code'])->toBe('forbidden');
});

it('allows an active operator to use mail server read and write', function (): void {
    [, , $token] = installScopedApiKey(
        PlatformRole::Operator,
        ['mail_servers:read', 'mail_servers:write'],
        'mail_servers:write',
    );

    expect(runScopedPipeline($token, 'mail_servers:write')->getStatusCode())->toBe(200);
});

it('denies an operator key that stores mail_servers:admin', function (): void {
    [, , $token] = installScopedApiKey(
        PlatformRole::Operator,
        ['mail_servers:admin'],
        'mail_servers:write',
    );

    $response = runScopedPipeline($token, 'mail_servers:write');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['error']['code'])->toBe('forbidden');
});

it('allows an active admin to satisfy mail server admin and implied write', function (): void {
    [, , $token] = installScopedApiKey(
        PlatformRole::Admin,
        ['mail_servers:admin'],
        'mail_servers:write',
    );

    expect(runScopedPipeline($token, 'mail_servers:write')->getStatusCode())->toBe(200)
        ->and(runScopedPipeline($token, 'mail_servers:admin')->getStatusCode())->toBe(200);
});

it('fails closed after admin is demoted to operator while retaining admin scope', function (): void {
    $demoted = User::factory()->create(['platform_role' => PlatformRole::Operator]);
    $hasher = new ApiKeyTokenHasher;
    $credentials = (new ApiKeyTokenGenerator($hasher))->generate();
    $legacyKey = new ApiKey([
        'user_id' => $demoted->id,
        'name' => 'demoted-admin-key',
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => ['mail_servers:admin'],
        'rate_limit_per_minute' => 60,
    ]);
    $legacyKey->setRelation('user', $demoted);
    $repository = Mockery::mock(ApiKeyRepositoryInterface::class);
    $repository->allows('findActiveByPrefixAndHash')->andReturn($legacyKey);
    app()->instance(ApiKeyResolver::class, new ApiKeyResolver($repository, $hasher));

    expect(runScopedPipeline($credentials['plain_token'], 'mail_servers:admin')->getStatusCode())->toBe(403)
        ->and(runScopedPipeline($credentials['plain_token'], 'mail_servers:write')->getStatusCode())->toBe(403);
});

it('fails closed after operator is demoted to user while retaining mail server scopes', function (): void {
    $demoted = User::factory()->create(['platform_role' => PlatformRole::User]);
    $hasher = new ApiKeyTokenHasher;
    $credentials = (new ApiKeyTokenGenerator($hasher))->generate();
    $legacyKey = new ApiKey([
        'user_id' => $demoted->id,
        'name' => 'demoted-operator-key',
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => ['mail_servers:read'],
        'rate_limit_per_minute' => 60,
    ]);
    $legacyKey->setRelation('user', $demoted);
    $repository = Mockery::mock(ApiKeyRepositoryInterface::class);
    $repository->allows('findActiveByPrefixAndHash')->andReturn($legacyKey);
    app()->instance(ApiKeyResolver::class, new ApiKeyResolver($repository, $hasher));

    expect(runScopedPipeline($credentials['plain_token'], 'mail_servers:read')->getStatusCode())->toBe(403);
});

it('denies suspended and banned owners on scoped requests', function (UserStatus $status): void {
    [, , $token] = installScopedApiKey(
        PlatformRole::Operator,
        ['mail_servers:read'],
        'mail_servers:read',
        $status,
    );

    $response = runScopedPipeline($token, 'mail_servers:read');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['error']['code'])->toBe('forbidden');
})->with([
    UserStatus::Suspended,
    UserStatus::Banned,
]);

it('returns 401 when the owner is missing or soft-deleted', function (): void {
    $hasher = new ApiKeyTokenHasher;
    $credentials = (new ApiKeyTokenGenerator($hasher))->generate();
    $orphanKey = new ApiKey([
        'user_id' => null,
        'name' => 'orphan-key',
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => ['inboxes:read'],
        'rate_limit_per_minute' => 60,
    ]);
    $orphanKey->setRelation('user', null);
    $repository = Mockery::mock(ApiKeyRepositoryInterface::class);
    $repository->allows('findActiveByPrefixAndHash')->andReturn($orphanKey);
    app()->instance(ApiKeyResolver::class, new ApiKeyResolver($repository, $hasher));

    expect(runScopedPipeline($credentials['plain_token'], 'inboxes:read')->getStatusCode())->toBe(401);

    $deleted = User::factory()->create(['platform_role' => PlatformRole::Operator]);
    $deletedCredentials = (new ApiKeyTokenGenerator($hasher))->generate();
    $deletedKey = new ApiKey([
        'user_id' => $deleted->id,
        'name' => 'deleted-owner-key',
        'key_prefix' => $deletedCredentials['key_prefix'],
        'key_hash' => $deletedCredentials['key_hash'],
        'permissions' => ['mail_servers:read'],
        'rate_limit_per_minute' => 60,
    ]);
    $deleted->delete();
    // Soft-deleted owners must not be attached; relation resolves to null.
    $deletedKey->setRelation('user', null);
    $repository = Mockery::mock(ApiKeyRepositoryInterface::class);
    $repository->allows('findActiveByPrefixAndHash')->andReturn($deletedKey);
    app()->instance(ApiKeyResolver::class, new ApiKeyResolver($repository, $hasher));

    $response = runScopedPipeline($deletedCredentials['plain_token'], 'mail_servers:read');
    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['error']['code'])->toBe('unauthenticated');
});

it('fails closed for unknown legacy stored scopes', function (): void {
    $user = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    $hasher = new ApiKeyTokenHasher;
    $credentials = (new ApiKeyTokenGenerator($hasher))->generate();
    $apiKey = new ApiKey([
        'user_id' => $user->id,
        'name' => 'legacy-unknown',
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => ['mail_servers:read', 'not:a_scope'],
        'rate_limit_per_minute' => 60,
    ]);
    $apiKey->setRelation('user', $user);
    $repository = Mockery::mock(ApiKeyRepositoryInterface::class);
    $repository->allows('findActiveByPrefixAndHash')->andReturn($apiKey);
    app()->instance(ApiKeyResolver::class, new ApiKeyResolver($repository, $hasher));

    expect(runScopedPipeline($credentials['plain_token'], 'mail_servers:read')->getStatusCode())->toBe(403);
});

it('fails closed when an unrelated unauthorized stored scope exists alongside a valid one', function (): void {
    [, , $token] = installScopedApiKey(
        PlatformRole::User,
        ['inboxes:read', 'mail_servers:write'],
        'inboxes:read',
    );

    expect(runScopedPipeline($token, 'inboxes:read')->getStatusCode())->toBe(403);
});

it('returns forbidden for a missing requested scope when stored scopes are otherwise valid', function (): void {
    [, , $token] = installScopedApiKey(
        PlatformRole::Operator,
        ['mail_servers:read'],
        'mail_servers:write',
    );

    $response = runScopedPipeline($token, 'mail_servers:write');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['error']['code'])->toBe('forbidden');
});
