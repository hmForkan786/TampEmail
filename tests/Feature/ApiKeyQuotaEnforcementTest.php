<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\DTOs\ApiKey\CreateApiKeyData;
use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Exceptions\ApiKeyQuotaExceededException;
use App\Models\ApiKey;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'api-key-quota-test-secret']);
});

function apiKeyQuotaUser(?int $limit): User
{
    $user = User::factory()->create();
    $plan = Plan::create([
        'slug' => 'api-key-quota-'.uniqid(),
        'name' => 'API key quota',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $feature = Feature::query()->firstOrCreate(
        ['key' => 'max_api_keys'],
        ['name' => 'Max API keys', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 1],
    );
    $plan->features()->attach($feature->id, ['feature_value' => ['limit' => $limit]]);
    Subscription::create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'auto_renew' => true,
        'price' => '0.00',
        'currency' => 'USD',
    ]);

    return $user;
}

function executeApiKeyIssue(User $user, string $name = 'quota-key'): ApiKey
{
    return app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: $name,
        user: $user,
    )->apiKey;
}

it('enforces the quota boundary and does not persist a rejected key', function (): void {
    $user = apiKeyQuotaUser(1);
    executeApiKeyIssue($user, 'first');

    expect(fn () => executeApiKeyIssue($user, 'second'))->toThrow(ApiKeyQuotaExceededException::class);
    expect(ApiKey::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('excludes revoked keys but counts expired non-revoked keys', function (): void {
    $user = apiKeyQuotaUser(1);
    $first = executeApiKeyIssue($user, 'first');
    $first->update(['revoked_at' => now()]);
    executeApiKeyIssue($user, 'second');

    $second = ApiKey::query()->where('user_id', $user->id)->whereNull('revoked_at')->firstOrFail();
    $second->update(['expires_at' => now()->subMinute()]);

    expect(fn () => executeApiKeyIssue($user, 'third'))->toThrow(ApiKeyQuotaExceededException::class);
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
