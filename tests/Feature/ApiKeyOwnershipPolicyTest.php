<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\DTOs\ApiKey\CreateApiKeyData;
use App\Exceptions\ApiKeyOwnerRequiredException;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'ownership-policy-test-secret']);
});

function ownershipApiKeyData(string $userId): CreateApiKeyData
{
    $credentials = app(ApiKeyTokenGenerator::class)->generate();

    return new CreateApiKeyData(
        userId: $userId,
        name: 'ownership-test',
        keyPrefix: $credentials['key_prefix'],
        keyHash: $credentials['key_hash'],
        permissions: null,
        rateLimitPerMinute: 60,
        expiresAt: null,
        revokedAt: null,
        metadata: null,
    );
}

it('rejects execute without an owner', function (): void {
    expect(fn () => app(CreateApiKeyAction::class)->execute(ownershipApiKeyData('')))
        ->toThrow(ApiKeyOwnerRequiredException::class);

    expect(ApiKey::query()->count())->toBe(0);
});

it('rejects issue without an owner', function (): void {
    expect(fn () => app(CreateApiKeyAction::class)->issue('', 'ownerless'))
        ->toThrow(ApiKeyOwnerRequiredException::class);

    expect(ApiKey::query()->count())->toBe(0);
});

it('uses the supplied user when execute data has no user id', function (): void {
    $user = User::factory()->create();

    $key = app(CreateApiKeyAction::class)->execute(ownershipApiKeyData(''), $user);

    expect($key->user_id)->toBe($user->id);
});

it('rejects an invalid user id', function (): void {
    expect(fn () => app(CreateApiKeyAction::class)->execute(ownershipApiKeyData('missing-user')))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('rejects a mismatched supplied user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect(fn () => app(CreateApiKeyAction::class)->execute(ownershipApiKeyData($other->id), $user))
        ->toThrow(InvalidArgumentException::class);
});
