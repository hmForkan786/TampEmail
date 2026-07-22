<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\PlatformRole;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'request-log-test-secret']);
});

function requestLogApiKey(array $scopes = ['mail_servers:read'], int $limit = 60): array
{
    $user = User::factory()->create(['platform_role' => PlatformRole::Operator]);
    $issued = app(CreateApiKeyAction::class)->issue(userId: $user->id, name: 'request-log', permissions: $scopes, user: $user);
    $issued->apiKey->update(['rate_limit_per_minute' => $limit]);
    RateLimiter::clear('api-key:'.$issued->apiKey->id);
    return [$user, $issued->plainToken, $issued->apiKey];
}

it('logs successful, scope-denied, authentication-failed and throttled API requests safely', function (): void {
    [$user, $token, $key] = requestLogApiKey(['mail_servers:read'], 1);
    $this->withToken($token)->getJson('/api/v1/mail-servers')->assertOk();
    $this->withToken($token)->getJson('/api/v1/mail-servers')->assertStatus(429);
    $this->withToken($token)->postJson('/api/v1/mail-servers', [])->assertStatus(403);
    $this->withHeader('Authorization', 'Basic invalid')->getJson('/api/v1/mail-servers')->assertStatus(401);

    expect(ApiRequestLog::query()->count())->toBe(4);
    $success = ApiRequestLog::query()->where('response_status', 200)->sole();
    $throttle = ApiRequestLog::query()->where('response_status', 429)->sole();
    expect($success->api_key_id)->toBe((string) $key->id)
        ->and($success->user_id)->toBe((string) $user->id)
        ->and($success->method)->toBe('GET')
        ->and($success->endpoint)->toBe('api.v1.mail-servers.index')
        ->and($success->response_time_ms)->toBeGreaterThanOrEqual(0)
        ->and($throttle->metadata['was_throttled'])->toBeTrue()
        ->and($throttle->api_key_id)->toBe((string) $key->id);

    $json = ApiRequestLog::query()->get()->toJson();
    expect($json)->not->toContain($token)->not->toContain($key->key_hash)->not->toContain('Authorization');
});

it('does not log web requests', function (): void {
    $this->get('/')->assertOk();
    expect(ApiRequestLog::query()->count())->toBe(0);
});
