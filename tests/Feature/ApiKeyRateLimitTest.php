<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'rate-limit-test-secret']);
});

function rateLimitKey(int $limit): array
{
    $user = User::factory()->create(['platform_role' => PlatformRole::Operator]);
    $issued = app(CreateApiKeyAction::class)->issue(userId: $user->id, name: 'rate-limit', permissions: ['mail_servers:read'], user: $user);
    $issued->apiKey->update(['rate_limit_per_minute' => $limit]);
    RateLimiter::clear('api-key:'.$issued->apiKey->id);
    return [$issued->plainToken, $issued->apiKey];
}

it('enforces an isolated per-api-key limit with standard headers', function (): void {
    [$token, $key] = rateLimitKey(1);
    $first = $this->withToken($token)->getJson('/api/v1/mail-servers');
    $first->assertOk()->assertHeader('X-RateLimit-Limit', '1')->assertHeader('X-RateLimit-Remaining', '0');

    $second = $this->withToken($token)->getJson('/api/v1/mail-servers');
    $second->assertStatus(429)->assertHeader('X-RateLimit-Limit', '1')->assertHeader('X-RateLimit-Remaining', '0')->assertHeader('Retry-After');
    expect($second->json('error.code'))->toBe('rate_limit_exceeded')
        ->and(json_encode($second->json()))->not->toContain($token)->not->toContain($key->key_hash);
});

it('does not share buckets between API keys and uses configured fallback for invalid limits', function (): void {
    [$tokenA] = rateLimitKey(1);
    [$tokenB] = rateLimitKey(1);
    $this->withToken($tokenA)->getJson('/api/v1/mail-servers')->assertOk();
    $this->withToken($tokenB)->getJson('/api/v1/mail-servers')->assertOk();

    config(['abuse.rate_limits.api_per_minute' => 2]);
    [$fallbackToken] = rateLimitKey(0);
    $this->withToken($fallbackToken)->getJson('/api/v1/mail-servers')->assertHeader('X-RateLimit-Limit', '2');
});
