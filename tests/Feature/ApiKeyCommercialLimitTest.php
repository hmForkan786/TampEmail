<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Exceptions\ApiKeyQuotaExceededException;
use App\Models\ApiKey;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['api.key_hash_secret' => 'api-key-commercial-limit-secret']));

it('creates below the limit and denies at the boundary', function (): void {
    $user = apiKeyQuotaUser(2);
    executeApiKeyIssue($user, 'first');
    executeApiKeyIssue($user, 'second');

    expect(fn () => executeApiKeyIssue($user, 'third'))->toThrow(ApiKeyQuotaExceededException::class);
    expect(AuditLog::query()->where('action', 'commercial.api_key_limit_reached')->exists())->toBeTrue();
});

it('ignores revoked and expired keys in active counts', function (): void {
    $user = apiKeyQuotaUser(1);
    $first = executeApiKeyIssue($user, 'first');
    $first->update(['revoked_at' => now()]);
    $second = executeApiKeyIssue($user, 'second');

    expect($second->refresh()->revoked_at)->toBeNull();
});

it('enforces concurrent quota at the boundary', function (): void {
    $user = apiKeyQuotaUser(1);
    executeApiKeyIssue($user, 'first');

    expect(fn () => app(CreateApiKeyAction::class)->issue(userId: $user->id, name: 'second', user: $user))
        ->toThrow(ApiKeyQuotaExceededException::class);
    expect(ApiKey::query()->where('user_id', $user->id)->available()->count())->toBe(1);
});

it('isolates limits between users', function (): void {
    $first = apiKeyQuotaUser(1);
    $second = apiKeyQuotaUser(1);
    executeApiKeyIssue($first, 'a');
    executeApiKeyIssue($second, 'b');

    expect(ApiKey::query()->where('user_id', $first->id)->available()->count())->toBe(1)
        ->and(ApiKey::query()->where('user_id', $second->id)->available()->count())->toBe(1);
});
