<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Actions\ApiKey\RotateApiKeyAction;
use App\Exceptions\ApiKeyRotationNotAllowedException;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['api.key_hash_secret' => 'api-key-rotation-secret']));

it('rotates at the key limit without increasing active count', function (): void {
    $user = apiKeyQuotaUser(1);
    $issued = app(CreateApiKeyAction::class)->issue(userId: $user->id, name: 'rotate-me', user: $user);
    $rotated = app(RotateApiKeyAction::class)->execute($user, $issued->apiKey);

    expect(ApiKey::query()->where('user_id', $user->id)->available()->count())->toBe(1)
        ->and($issued->apiKey->fresh()->revoked_at)->not->toBeNull()
        ->and($rotated->plainToken)->not->toBe($issued->plainToken);
    expect(AuditLog::query()->where('action', 'commercial.api_key_rotated')->exists())->toBeTrue();
});

it('rejects rotation for revoked keys and leaves the old key valid on failure', function (): void {
    $user = apiKeyQuotaUser(2);
    $issued = app(CreateApiKeyAction::class)->issue(userId: $user->id, name: 'revoked', user: $user);
    $issued->apiKey->update(['revoked_at' => now()]);

    expect(fn () => app(RotateApiKeyAction::class)->execute($user, $issued->apiKey->fresh()))
        ->toThrow(ApiKeyRotationNotAllowedException::class);
});

it('authenticates only the replacement key after rotation', function (): void {
    ['user' => $user, 'token' => $token] = premiumWebhookFixture();
    $issued = app(CreateApiKeyAction::class)->issue(userId: $user->id, name: 'auth', permissions: ['outbound_messages:read'], user: $user);
    $rotated = app(RotateApiKeyAction::class)->execute($user, $issued->apiKey);

    $this->withToken($rotated->plainToken)->getJson('/api/v1/outbound-drafts')->assertOk();
    $this->withToken($issued->plainToken)->getJson('/api/v1/outbound-drafts')->assertUnauthorized();
});

it('returns plaintext only once in the rotation result', function (): void {
    $user = User::factory()->create();
    $issued = app(CreateApiKeyAction::class)->issue(userId: $user->id, name: 'once', user: $user);
    $rotated = app(RotateApiKeyAction::class)->execute($user, $issued->apiKey);

    expect($rotated->plainToken)->not->toBeEmpty()
        ->and(json_encode(AuditLog::query()->where('action', 'commercial.api_key_rotated')->get()))->not->toContain($rotated->plainToken);
});
