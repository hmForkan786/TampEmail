<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\DTOs\ApiKey\CreateApiKeyData;
use App\Exceptions\ApiKeyQuotaExceededException;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'api-key-quota-test-secret']);
});

it('enforces the quota boundary and does not persist a rejected key', function (): void {
    $user = apiKeyQuotaUser(1);
    executeApiKeyIssue($user, 'first');

    expect(fn () => executeApiKeyIssue($user, 'second'))->toThrow(ApiKeyQuotaExceededException::class);
    expect(ApiKey::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('excludes revoked and expired keys from the active key limit', function (): void {
    $user = apiKeyQuotaUser(1);
    $first = executeApiKeyIssue($user, 'first');
    $first->update(['revoked_at' => now()]);
    executeApiKeyIssue($user, 'second');

    $second = ApiKey::query()->where('user_id', $user->id)->whereNull('revoked_at')->firstOrFail();
    $second->update(['expires_at' => now()->subMinute()]);

    expect(executeApiKeyIssue($user, 'third'))->toBeInstanceOf(ApiKey::class);
});

it('allows unlimited quota and isolates users', function (): void {
    $unlimited = apiKeyQuotaUser(null);
    executeApiKeyIssue($unlimited, 'one');
    executeApiKeyIssue($unlimited, 'two');

    $other = apiKeyQuotaUser(1);
    $key = executeApiKeyIssue($other, 'other');

    expect($key->user_id)->toBe($other->id);
});

it('resolves userId and enforces quota when the user argument is null', function (): void {
    $user = apiKeyQuotaUser(1);
    executeApiKeyIssue($user, 'first');

    expect(fn () => app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'second',
        user: null,
    ))->toThrow(ApiKeyQuotaExceededException::class);
});

it('applies the same quota behavior to execute()', function (): void {
    $user = apiKeyQuotaUser(1);
    executeApiKeyIssue($user, 'first');
    $credentials = app(ApiKeyTokenGenerator::class)->generate();
    $data = new CreateApiKeyData(
        userId: $user->id,
        name: 'second',
        keyPrefix: $credentials['key_prefix'],
        keyHash: $credentials['key_hash'],
        permissions: null,
        rateLimitPerMinute: 60,
        expiresAt: null,
        revokedAt: null,
        metadata: null,
    );

    expect(fn () => app(CreateApiKeyAction::class)->execute($data))->toThrow(ApiKeyQuotaExceededException::class);
});

it('rejects a userId mismatch instead of silently accepting it', function (): void {
    $user = apiKeyQuotaUser(1);
    $other = User::factory()->create();

    expect(fn () => app(CreateApiKeyAction::class)->issue(
        userId: $other->id,
        name: 'mismatch',
        user: $user,
    ))->toThrow(InvalidArgumentException::class);
});
