<?php

use App\Models\ApiKey;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'bulk-revoke-test-secret']);
});

/**
 * @param  list<string>|null  $permissions
 */
function persistApiKeyForBulkRevoke(User $user, ?array $permissions, array $overrides = []): ApiKey
{
    $credentials = app(ApiKeyTokenGenerator::class)->generate();

    return ApiKey::query()->create(array_merge([
        'user_id' => $user->id,
        'name' => 'bulk-revoke-'.uniqid(),
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => $permissions,
        'rate_limit_per_minute' => 60,
    ], $overrides));
}

it('revokes a key that matches one requested scope', function (): void {
    $user = User::factory()->create();
    $matching = persistApiKeyForBulkRevoke($user, ['mail_servers:admin', 'inboxes:read']);
    $revokedAt = Carbon::parse('2026-07-22 12:00:00');

    $count = app(ApiKeyRepositoryInterface::class)->revokeUnrevokedForUserWithAnyScope(
        $user->id,
        ['mail_servers:admin'],
        $revokedAt,
    );

    expect($count)->toBe(1)
        ->and($matching->fresh()->revoked_at?->equalTo($revokedAt))->toBeTrue();
});

it('revokes when any of multiple requested scopes match and does not double-count', function (): void {
    $user = User::factory()->create();
    $read = persistApiKeyForBulkRevoke($user, ['mail_servers:read']);
    $write = persistApiKeyForBulkRevoke($user, ['mail_servers:write', 'inboxes:read']);
    $inboxOnly = persistApiKeyForBulkRevoke($user, ['inboxes:read']);
    $revokedAt = now();

    $count = app(ApiKeyRepositoryInterface::class)->revokeUnrevokedForUserWithAnyScope(
        $user->id,
        ['mail_servers:read', 'mail_servers:write', 'mail_servers:read'],
        $revokedAt,
    );

    expect($count)->toBe(2)
        ->and($read->fresh()->revoked_at)->not->toBeNull()
        ->and($write->fresh()->revoked_at)->not->toBeNull()
        ->and($inboxOnly->fresh()->revoked_at)->toBeNull();
});

it('leaves non-matching and other-user keys unchanged', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $nonMatching = persistApiKeyForBulkRevoke($user, ['inboxes:write']);
    $otherMatching = persistApiKeyForBulkRevoke($other, ['mail_servers:read']);

    $count = app(ApiKeyRepositoryInterface::class)->revokeUnrevokedForUserWithAnyScope(
        $user->id,
        ['mail_servers:read', 'mail_servers:write', 'mail_servers:admin'],
        now(),
    );

    expect($count)->toBe(0)
        ->and($nonMatching->fresh()->revoked_at)->toBeNull()
        ->and($otherMatching->fresh()->revoked_at)->toBeNull();
});

it('does not overwrite already revoked keys', function (): void {
    $user = User::factory()->create();
    $original = Carbon::parse('2026-01-01 00:00:00');
    $alreadyRevoked = persistApiKeyForBulkRevoke($user, ['mail_servers:admin'], [
        'revoked_at' => $original,
    ]);

    $count = app(ApiKeyRepositoryInterface::class)->revokeUnrevokedForUserWithAnyScope(
        $user->id,
        ['mail_servers:admin'],
        Carbon::parse('2026-07-22 15:00:00'),
    );

    expect($count)->toBe(0)
        ->and($alreadyRevoked->fresh()->revoked_at?->equalTo($original))->toBeTrue();
});

it('revokes expired but non-revoked matching keys', function (): void {
    $user = User::factory()->create();
    $expired = persistApiKeyForBulkRevoke($user, ['mail_servers:write'], [
        'expires_at' => now()->subHour(),
    ]);
    $revokedAt = now();

    $count = app(ApiKeyRepositoryInterface::class)->revokeUnrevokedForUserWithAnyScope(
        $user->id,
        ['mail_servers:write'],
        $revokedAt,
    );

    expect($count)->toBe(1)
        ->and($expired->fresh()->revoked_at)->not->toBeNull();
});

it('does not match scopes by substring', function (): void {
    $user = User::factory()->create();
    $lookalike = persistApiKeyForBulkRevoke($user, ['mail_servers:read_only', 'xmail_servers:admin']);
    $exact = persistApiKeyForBulkRevoke($user, ['mail_servers:read']);

    $count = app(ApiKeyRepositoryInterface::class)->revokeUnrevokedForUserWithAnyScope(
        $user->id,
        ['mail_servers:read', 'mail_servers:admin'],
        now(),
    );

    expect($count)->toBe(1)
        ->and($exact->fresh()->revoked_at)->not->toBeNull()
        ->and($lookalike->fresh()->revoked_at)->toBeNull();
});

it('revokes all non-revoked keys for a user including expired ones', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $active = persistApiKeyForBulkRevoke($user, ['inboxes:read']);
    $expired = persistApiKeyForBulkRevoke($user, null, ['expires_at' => now()->subMinute()]);
    $alreadyRevokedAt = Carbon::parse('2026-02-02 02:02:02');
    $already = persistApiKeyForBulkRevoke($user, ['mail_servers:read'], [
        'revoked_at' => $alreadyRevokedAt,
    ]);
    $otherKey = persistApiKeyForBulkRevoke($other, ['mail_servers:admin']);
    $revokedAt = Carbon::parse('2026-07-22 18:00:00');

    $count = app(ApiKeyRepositoryInterface::class)->revokeAllUnrevokedForUser(
        $user->id,
        $revokedAt,
    );

    expect($count)->toBe(2)
        ->and($active->fresh()->revoked_at?->equalTo($revokedAt))->toBeTrue()
        ->and($expired->fresh()->revoked_at?->equalTo($revokedAt))->toBeTrue()
        ->and($already->fresh()->revoked_at?->equalTo($alreadyRevokedAt))->toBeTrue()
        ->and($otherKey->fresh()->revoked_at)->toBeNull();
});

it('returns zero when the requested scope list is empty', function (): void {
    $user = User::factory()->create();
    persistApiKeyForBulkRevoke($user, ['mail_servers:admin']);

    $count = app(ApiKeyRepositoryInterface::class)->revokeUnrevokedForUserWithAnyScope(
        $user->id,
        [],
        now(),
    );

    expect($count)->toBe(0)
        ->and(ApiKey::query()->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(1);
});
